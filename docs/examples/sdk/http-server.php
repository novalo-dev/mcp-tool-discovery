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
 *
 * A client of the stateless revision 2026-07-28 that opens a
 * `subscriptions/listen` stream is sent notifications/tools/list_changed when
 * the list its caller may see changes. To watch it: MCP_NOTES_MODE=direct
 * lists every tool as itself, MCP_NOTES_GRANTS names the grants file of
 * grantedRole() in http.php, and docs/examples/sdk/grant.php changes it.
 * PHP's built-in server answers one request at a time unless
 * PHP_CLI_SERVER_WORKERS is set, so a stream would hold it alone.
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
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

use function Example\Notes\definitions;
use function Example\Notes\grantedRole;
use function Example\Notes\roleOf;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require dirname(__DIR__) . '/http.php';
require __DIR__ . '/bridge.php';
require __DIR__ . '/notifications.php';

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
// Workers started together race to make it: the one that loses finds it made.
if (!is_dir($data) && !@mkdir($data, 0o700, true) && !is_dir($data)) {
    throw new \RuntimeException('Cannot make ' . $data . '.');
}

$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
$notes = new Notes($data . '/notes.json');
$search = new SearchSetup(cache: new FileIndexCache($data . '/index'));
// Compact by default. In compact mode a change of rights moves no line of
// the list, only what the search finds, so there would be nothing to send:
// MCP_NOTES_MODE=direct lists every tool as itself, and the list moves.
$mode = getenv('MCP_NOTES_MODE') === 'direct' ? new DirectMode() : new CompactMode(prefix: 'notes_', batchLimit: 20);
// One Discovery per caller, built with the rights the caller has now. The
// cache is keyed by the tools a caller may see, so callers share it safely.
// No learning store: it would be shared by every caller, which is a choice
// for the host to make, as docs/HOSTS.md says. A stream that stays open asks
// again at each change, so that a right granted meanwhile is seen.
$discoveryOf = static fn(): Discovery => new Discovery($mode, new NotesGate(grantedRole($role), $notes), $search);
$discovery = $discoveryOf();
// Only the stateless revision has a stream the SDK sends notifications on;
// a client of a revision with a session would be promised them for nothing.
$stateless = $request->getHeaderLine('MCP-Protocol-Version') === Revision::V2026_07_28->value;

$server = Server::builder()
    ->setServerInfo('notes', '1.0.0')
    // The registry is empty, so the SDK would not announce tools by itself.
    ->setCapabilities(new ServerCapabilities(tools: true, toolsListChanged: $stateless, resources: false, prompts: false))
    ->addRequestHandler(new ToolsHandler($discovery, $tools))
    // A listen stream belongs to the caller of its request, so it reads the
    // shared bus through this caller's list, in the revision it listens in.
    ->setNotificationBus(new CallerNotifications(notificationBus($data), $discoveryOf, $tools, Revision::V2026_07_28))
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
