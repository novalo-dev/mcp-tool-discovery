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
 * The notes server on the official MCP PHP SDK, over its Streamable HTTP
 * transport, one process per request as PHP-FPM runs it. The handler is the
 * one of docs/examples/sdk/server.php; what changes is that the caller is no
 * longer the process: every request names its own, and the gate and the
 * Discovery are built for it.
 *
 * Install: composer install, in this directory.
 * Run it:   php -S 127.0.0.1:8080 docs/examples/sdk/http-server.php
 * Call it:  POST http://127.0.0.1:8080/mcp, with Authorization: Bearer reader-token
 */

namespace Example\Notes\Sdk;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Http\Discovery\Psr17Factory;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Server;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

use function Example\Notes\definitions;
use function Example\Notes\roleOf;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require dirname(__DIR__) . '/http.php';
require __DIR__ . '/bridge.php';

$factory = new Psr17Factory();
$request = $factory->createServerRequestFromGlobals();

// The caller decides what the gate shows, so a request that names no one is
// answered before the SDK reads it. A real server checks an OAuth access
// token here, or lets its framework do it.
$role = roleOf(static fn(string $name): ?string => $request->hasHeader($name) ? $request->getHeaderLine($name) : null);
if ($role === null) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer');

    return;
}

// Each request is a new process, so nothing in memory reaches the next one:
// the notes, the sessions and the search index are kept in files.
$data = getenv('MCP_NOTES_DATA');
// A directory only this server writes to: the index cache runs its files.
$data = is_string($data) && $data !== '' ? $data : dirname(__DIR__) . '/var/sdk';
if (!is_dir($data)) {
    mkdir($data, 0o700, true);
}

$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
// One Discovery per caller, built for this request with its gate. The cache
// is keyed by the tools a caller may see, so callers share it safely. No
// learning store: it would be shared by every caller, which is a choice for
// the host to make, as docs/HOSTS.md says.
$discovery = new Discovery(
    new CompactMode(prefix: 'notes_', batchLimit: 20),
    new NotesGate($role, new Notes($data . '/notes.json')),
    new SearchSetup(cache: new FileIndexCache($data . '/index')),
);

$server = Server::builder()
    ->setServerInfo('notes', '1.0.0')
    // The registry is empty, so the SDK would not announce tools by itself.
    ->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: false))
    ->addRequestHandler(new ToolsHandler($discovery, $tools))
    // Without a store the SDK serves HTTP clients with no session at all; in
    // files, the revision a client agreed on reaches its next requests.
    ->setSession(new FileSessionStore($data . '/sessions'))
    ->setSessionLock(new FileSessionLock($data . '/sessions'))
    ->build();

// A session serves only the caller that opened it: another token sent with
// its Mcp-Session-Id is refused by the SDK, so one caller never runs on the
// session, and the revision, of another. The role stands for the caller in
// this example; a real server names the user.
$response = $server->run(new StreamableHttpTransport($request->withAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE, $role), $factory, $factory));

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();
