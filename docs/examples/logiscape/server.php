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
 * The notes server of the other examples, on logiscape/mcp-sdk-php, the
 * other MCP SDK for PHP many servers run on. The SDK keeps the transport,
 * the handshake, the stateless revision and JSON-RPC errors. The library
 * answers tools/list and tools/call, the two handlers registered on the
 * SDK's Server, which keys its handlers by method. McpServer, the SDK's
 * wrapper, is left out: it would add handlers for prompts and resources the
 * server does not have, and the client would be told they exist.
 *
 * Install: composer install, in this directory.
 * Run it:   php docs/examples/logiscape/server.php
 * As admin: MCP_NOTES_ROLE=admin php docs/examples/logiscape/server.php
 */

namespace Example\Notes\Logiscape;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioServerTransport;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;
use Mcp\Types\Result;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Exception\InvalidCursor;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

use function Example\Notes\definitions;
use function Example\Notes\instructions;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require __DIR__ . '/bridge.php';

$role = getenv('MCP_NOTES_ROLE');
$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
// One Discovery per caller. Over stdio the caller is the process for its whole
// life; over HTTP, build it per request, with that request's gate.
$discovery = new Discovery(
    new CompactMode(prefix: 'notes_', batchLimit: 20),
    new NotesGate(is_string($role) ? $role : 'reader', new Notes()),
    new SearchSetup(cache: new MemoryIndexCache(), learning: new MemoryLearningStore()),
);

$server = new Server('notes');

$server->registerHandler('tools/list', static function (mixed $params) use ($discovery, $tools, $server): Result {
    $cursor = is_object($params) && isset($params->cursor) && is_string($params->cursor) ? $params->cursor : null;
    try {
        return answer($discovery->listPage($tools, revision($server), $cursor, 50));
    } catch (InvalidCursor $error) {
        // The client's mistake, so the invalid params error the specification
        // asks for instead of the internal error the SDK would send.
        throw new McpError(new ErrorData(-32602, $error->getMessage()));
    }
});

$server->registerHandler('tools/call', static function (mixed $params) use ($discovery, $tools, $server): Result {
    $name = is_object($params) && isset($params->name) && is_string($params->name) ? $params->name : '';

    return answer($discovery->callTool($name, arguments($params), $tools, revision($server)));
});

// What the SDK's ServerRunner does over stdio, with the session that sends
// the instructions. The tools the mode lists do not change, so list_changed
// is not announced.
$transport = StdioServerTransport::create();
$session = new InstructedSession(instructions($discovery), $transport, $server->createInitializationOptions(new NotificationOptions()));
$server->setSession($session);
$session->registerHandlers($server->getHandlers());
$session->registerNotificationHandlers($server->getNotificationHandlers());
try {
    $session->start();
} finally {
    $session->stop();
    $transport->stop();
}
