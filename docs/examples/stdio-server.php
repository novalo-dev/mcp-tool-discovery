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
 * A complete MCP server over stdio, written by hand, with no SDK: a notes
 * server of four tools shown to the client in compact mode. It is the smallest
 * host the library can have, and the tests run it as a client would, so it
 * stays true to the library. The tools, the gate and the choice of revision
 * are in notes.php, shared with the HTTP example; this file is the transport.
 *
 * Run it:   php docs/examples/stdio-server.php
 * As admin: MCP_NOTES_ROLE=admin php docs/examples/stdio-server.php
 *
 * It speaks the revisions that open with an `initialize` handshake. The
 * stateless revision 2026-07-28 has none: how a request gives its version
 * there belongs to the transport, not to this library, and a host that serves
 * it passes that version to Revision::fromString() the same way.
 */

namespace Example\Notes;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

// In a project of your own: require 'vendor/autoload.php'.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/notes.php';

/**
 * Answers one JSON-RPC message, or returns null for a notification.
 *
 * @param array<mixed> $message
 *
 * @return array<string, mixed>|null
 */
function answer(array $message, Discovery $discovery, ToolSet $tools, ?Revision &$revision): ?array
{
    if (!array_key_exists('id', $message)) {
        return null;
    }
    $id = $message['id'];
    $method = is_string($message['method'] ?? null) ? $message['method'] : '';
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];

    switch ($method) {
        case 'initialize':
            $revision = Revision::negotiate($params['protocolVersion'] ?? null);

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => $revision->value,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'notes', 'version' => '1.0.0'],
            ]];
        case 'ping':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];
        case 'tools/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $discovery->listTools($tools, $revision ?? Revision::latestWithHandshake())];
        case 'tools/call':
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (!is_string($name) || !is_array($arguments)) {
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'tools/call needs a name and an object of arguments.']];
            }

            /** @var array<string, mixed> $arguments a JSON object decoded as an array */
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $discovery->callTool($name, $arguments, $tools, $revision ?? Revision::latestWithHandshake())];
    }

    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found.']];
}

$role = getenv('MCP_NOTES_ROLE');
$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
// One Discovery per caller. Over stdio the caller is the process for its whole
// life; over HTTP, build it per request, with that request's gate.
$discovery = new Discovery(
    new CompactMode(prefix: 'notes_', batchLimit: 20),
    new NotesGate(is_string($role) ? $role : 'reader', new Notes()),
);
$revision = null;

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    $message = json_decode($line, true);
    $reply = is_array($message)
        ? answer($message, $discovery, $tools, $revision)
        : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']];
    if ($reply !== null) {
        // The answers are already shaped for the revision, empty objects
        // included: encoding them as they are is enough.
        fwrite(STDOUT, json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
}
