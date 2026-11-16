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
 * server of four tools shown to the client in compact mode. It is the
 * smallest host the library can have, and the tests run it as a client would,
 * so it stays true to the library.
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
use Novalo\McpToolDiscovery\Exception\UnsupportedRevision;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

// In a project of your own: require 'vendor/autoload.php'.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * The host's own tools, exactly as its tools/list would have sent them.
 *
 * @return list<array<string, mixed>>
 */
function definitions(): array
{
    $id = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];

    return [
        [
            'name' => 'notes_list',
            'title' => 'List the notes',
            'description' => 'Lists every note, with its id and the start of its text.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['readOnlyHint' => true],
        ],
        [
            'name' => 'notes_get',
            'title' => 'Read a note',
            'description' => 'Returns the full text of one note.',
            'inputSchema' => $id,
            'annotations' => ['readOnlyHint' => true],
        ],
        [
            'name' => 'notes_add',
            'title' => 'Add a note',
            'description' => 'Adds a note and returns its id.',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
        ],
        [
            'name' => 'notes_delete',
            'title' => 'Delete a note',
            'description' => 'Deletes one note for good.',
            'inputSchema' => $id,
            'annotations' => ['destructiveHint' => true],
        ],
    ];
}

/**
 * Every access decision lives here, and nowhere in the library.
 */
final class NotesGate implements ToolGate
{
    /** @var array<int, string> */
    private array $notes = [1 => 'Back up the server before the upgrade.'];

    public function __construct(private readonly string $role) {}

    // A tool the caller could never run is not shown at all: here only an
    // admin may delete, so anyone else never learns the tool exists.
    public function visible(Tool $tool): bool
    {
        return $tool->name !== 'notes_delete' || $this->role === 'admin';
    }

    // Checked again with the arguments, at call time: the listing never saw them.
    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        if ($tool->name === 'notes_add' && strlen(is_string($arguments['text'] ?? null) ? $arguments['text'] : '') > 500) {
            return CallResult::error('A note holds at most 500 characters.');
        }

        return null;
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        $id = is_int($arguments['id'] ?? null) ? $arguments['id'] : 0;

        switch ($tool->name) {
            case 'notes_list':
                return CallResult::json(['notes' => array_map(
                    static fn(int $id, string $text): array => ['id' => $id, 'text' => substr($text, 0, 40)],
                    array_keys($this->notes),
                    array_values($this->notes),
                )]);
            case 'notes_get':
                return isset($this->notes[$id]) ? CallResult::text($this->notes[$id]) : CallResult::error('No note has this id.');
            case 'notes_add':
                $text = is_string($arguments['text'] ?? null) ? $arguments['text'] : '';
                if ($text === '') {
                    return CallResult::error('The text of the note is empty.');
                }
                $this->notes[] = $text;

                return CallResult::json(['id' => array_key_last($this->notes)]);
            case 'notes_delete':
                if (!isset($this->notes[$id])) {
                    return CallResult::error('No note has this id.');
                }
                unset($this->notes[$id]);

                return CallResult::text('Deleted.');
        }

        return CallResult::error('Unknown tool.');
    }
}

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
            // The client's revision if the library shapes answers for it,
            // otherwise the latest handshake revision, as the specification
            // asks of a server that does not support the client's.
            try {
                $revision = Revision::fromString(is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '');
            } catch (UnsupportedRevision) {
                $revision = Revision::V2025_11_25;
            }
            if ($revision->isStateless()) {
                $revision = Revision::V2025_11_25;
            }

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => $revision->value,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'notes', 'version' => '1.0.0'],
            ]];
        case 'ping':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];
        case 'tools/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $discovery->listTools($tools, $revision ?? Revision::V2025_11_25)];
        case 'tools/call':
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (!is_string($name) || !is_array($arguments)) {
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'tools/call needs a name and an object of arguments.']];
            }

            /** @var array<string, mixed> $arguments a JSON object decoded as an array */
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $discovery->callTool($name, $arguments, $tools, $revision ?? Revision::V2025_11_25)];
    }

    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found.']];
}

$role = getenv('MCP_NOTES_ROLE');
$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
// One Discovery per caller. Over stdio the caller is the process for its whole
// life; over HTTP, build it per request, with that request's gate.
$discovery = new Discovery(
    new CompactMode(prefix: 'notes_', batchLimit: 20),
    new NotesGate(is_string($role) ? $role : 'reader'),
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
