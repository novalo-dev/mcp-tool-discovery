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
 * The role read again at each message from a file, every tool listed as
 * itself:   MCP_NOTES_ROLE_FILE=role.txt MCP_NOTES_MODE=direct php ...
 *
 * It speaks the revisions that open with an `initialize` handshake. The
 * stateless revision 2026-07-28 has none: how a request gives its version
 * there belongs to the transport, not to this library, and a host that serves
 * it passes that version to Revision::fromString() the same way.
 */

namespace Example\Notes;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;
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
                // The server tells the client when the list it holds is no
                // longer right, so the client need not ask again to find out.
                'capabilities' => ['tools' => ['listChanged' => true]],
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

/**
 * The caller's role as it is now. With MCP_NOTES_ROLE_FILE it is read again at
 * each message, the way a real server asks its user store at each request: a
 * permission granted while the client is connected counts at once, and the
 * list the client holds may no longer be right.
 */
function role(): string
{
    $file = getenv('MCP_NOTES_ROLE_FILE');
    if (is_string($file) && $file !== '') {
        return is_file($file) ? trim((string) file_get_contents($file)) : 'reader';
    }
    $role = getenv('MCP_NOTES_ROLE');

    return is_string($role) ? $role : 'reader';
}

$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
$notes = new Notes();
// Four tools would fit in a list on their own; the compact mode is shown here
// because it is the one a large server uses. MCP_NOTES_MODE=direct lists
// every tool as itself.
$mode = getenv('MCP_NOTES_MODE') === 'direct' ? new DirectMode() : new CompactMode(prefix: 'notes_', batchLimit: 20);
// The index is built at the first search instead of at every one. The process
// serves one caller, so what its runs teach the search lifts only that
// caller's tools, for as long as the session lasts.
$search = new SearchSetup(cache: new MemoryIndexCache(), learning: new MemoryLearningStore());
$revision = null;
// The fingerprint of the list the client last received, or null before it
// asked for one.
$held = null;
$encode = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    // One Discovery per message, with the gate of the role as it is now. The
    // mode and the search setup stay, so the index and what the session
    // learnt are kept.
    $discovery = new Discovery($mode, new NotesGate(role(), $notes), $search);
    $message = json_decode($line, true);

    // A server written around a loop that waits for the next line can only
    // see a change when a message comes; one that hears of its changes on
    // its own sends the notification then. In compact mode the fingerprint
    // moves only when a pinned tool changes, so a role that shows one more
    // tool sends nothing, and a search finds the tool.
    if ($held !== null && is_array($message) && ($message['method'] ?? null) !== 'tools/list') {
        $now = $discovery->listFingerprint($tools, $revision ?? Revision::latestWithHandshake());
        if ($now !== $held) {
            fwrite(STDOUT, $encode(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']) . "\n");
            // Told once: the client asks again, and a list that changes back
            // is told again.
            $held = $now;
        }
    }

    $reply = is_array($message)
        ? answer($message, $discovery, $tools, $revision)
        : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']];
    if ($reply !== null) {
        if (is_array($message) && ($message['method'] ?? null) === 'tools/list' && isset($reply['result'])) {
            $held = $discovery->listFingerprint($tools, $revision ?? Revision::latestWithHandshake());
        }
        // The answers are already shaped for the revision, empty objects
        // included: encoding them as they are is enough.
        fwrite(STDOUT, $encode($reply) . "\n");
    }
}
