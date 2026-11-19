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
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Server;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Transport\StdioTransport;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

use function Example\Notes\definitions;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require __DIR__ . '/bridge.php';

/**
 * Answers tools/list and tools/call from the library. The SDK asks the
 * handlers a host adds before its own, so its registry of tools is never
 * reached and stays empty.
 *
 * @implements RequestHandlerInterface<Answer>
 */
final class ToolsHandler implements RequestHandlerInterface
{
    public function __construct(private readonly Discovery $discovery, private readonly ToolSet $tools) {}

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest || $request instanceof CallToolRequest;
    }

    /**
     * @return Response<Answer>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        $revision = revision($request, $session);

        if ($request instanceof CallToolRequest) {
            return new Response($request->getId(), new Answer($this->discovery->callTool($request->name, arguments($request), $this->tools, $revision)));
        }

        return new Response($request->getId(), new Answer($this->discovery->listTools($this->tools, $revision)));
    }
}

$role = getenv('MCP_NOTES_ROLE');
$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
// One Discovery per caller. Over stdio the caller is the process for its whole
// life; over HTTP, build it per request, with that request's gate.
$discovery = new Discovery(
    new CompactMode(prefix: 'notes_', batchLimit: 20),
    new NotesGate(is_string($role) ? $role : 'reader', new Notes()),
);

$server = Server::builder()
    ->setServerInfo('notes', '1.0.0')
    // The registry is empty, so the SDK would not announce tools by itself.
    ->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: false))
    ->addRequestHandler(new ToolsHandler($discovery, $tools))
    ->build();

$server->run(new StdioTransport());
