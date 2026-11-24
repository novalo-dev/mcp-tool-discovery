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
 * The notes server of the other examples, on the official MCP PHP SDK
 * (mcp/sdk) instead of a transport written by hand. The SDK keeps everything
 * it already does: the transport, the handshake, sessions, the stateless
 * revision, JSON-RPC errors. The library takes over only tools/list and
 * tools/call, through one request handler the SDK asks before its own.
 *
 * Install: composer install, in this directory.
 * Run it:   php docs/examples/sdk/server.php
 * As admin: MCP_NOTES_ROLE=admin php docs/examples/sdk/server.php
 */

namespace Example\Notes\Sdk;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;
use Novalo\McpToolDiscovery\Discovery;
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
    // The index is built at the first search instead of at every one. The
    // process serves one caller, so what its runs teach the search lifts
    // only that caller's tools, for as long as the session lasts.
    new SearchSetup(cache: new MemoryIndexCache(), learning: new MemoryLearningStore()),
);

$server = Server::builder()
    ->setServerInfo('notes', '1.0.0')
    // Read by the model before any tool: the list is not the whole server.
    ->setInstructions(instructions($discovery))
    // The registry is empty, so the SDK would not announce tools by itself.
    ->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: false))
    ->addRequestHandler(new ToolsHandler($discovery, $tools))
    ->build();

$server->run(new StdioTransport());
