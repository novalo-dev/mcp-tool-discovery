<?php

/*
 * This file is part of novalo/mcp-tool-discovery.
 *
 * (c) Novalo <https://novalo.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/*
 * The notes server on logiscape/mcp-sdk-php, over the SDK's HTTP runner, one
 * process per request as PHP-FPM runs it. The handlers are those of
 * docs/examples/logiscape/server.php; what changes is that the caller is no
 * longer the process: every request names its own, and the gate and the
 * Discovery are built for it.
 *
 * Install: composer install, in this directory.
 * Run it:   php -S 127.0.0.1:8080 docs/examples/logiscape/http-server.php
 * Call it:  POST http://127.0.0.1:8080/, with Authorization: Bearer reader-token
 */

namespace Example\Notes\Logiscape;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpMessage;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

use function Example\Notes\definitions;
use function Example\Notes\instructions;
use function Example\Notes\roleOf;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require dirname(__DIR__) . '/http.php';
require __DIR__ . '/bridge.php';

$request = HttpMessage::fromGlobals();

// The caller decides what the gate shows, so a request that names no one is
// answered before the SDK reads it. A real server checks an OAuth access
// token here, or lets its framework do it.
$role = roleOf(static fn(string $name): ?string => $request->getHeader($name));
if ($role === null) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer');

    return;
}

// Each request is a new process, so nothing in memory reaches the next one:
// the notes, the sessions and the search index are kept in files.
$data = getenv('MCP_NOTES_DATA');
// A directory only this server writes to: the index cache runs its files.
$data = is_string($data) && $data !== '' ? $data : dirname(__DIR__) . '/var/logiscape';
// Workers started together race to make it: the one that loses finds it made.
if (!is_dir($data) && !@mkdir($data, 0o700, true) && !is_dir($data)) {
    throw new \RuntimeException('Cannot make ' . $data . '.');
}

$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
// One Discovery per caller, built with the rights the caller has now. The
// cache is keyed by the tools a caller may see, so callers share it safely.
// No learning store: it would be shared by every caller, which is a choice
// for the host to make, as docs/HOSTS.md says.
$discovery = new Discovery(
    new CompactMode(prefix: 'notes_', batchLimit: 20),
    new NotesGate($role, new Notes($data . '/notes.json')),
    new SearchSetup(cache: new FileIndexCache($data . '/index')),
);

$server = new Server('notes');
serve($server, $discovery, $tools);

$runner = new HttpServerRunner(
    $server,
    // The tools the mode lists do not change, so list_changed is not announced.
    $server->createInitializationOptions(new NotificationOptions()),
    [
        // Answers in JSON, the body instruct() reads. The SDK leaves it off
        // by default; turned on, its answers would be streams of events.
        'enable_sse' => false,
        // The SDK checks Origin only when told which hosts may send one, and
        // its own McpServer sets them for the built-in server alone: a page
        // of another site must not reach a server on the developer's machine.
        'allowed_origins' => ['localhost', '127.0.0.1', '::1'],
    ],
    // Without a store the runner keeps sessions in memory, which a process
    // per request loses before the client's next request; in files, the
    // revision a client agreed on reaches its next requests.
    sessionStore: new FileSessionStore($data . '/sessions'),
);

$response = $runner->handleRequest($request);
// Read by the model before any tool: the list is not the whole server.
instruct($request, $response, instructions($discovery));
$runner->sendResponse($response);
