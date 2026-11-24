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
 * What the example servers share: the notes tools, where the notes are kept,
 * the gate, and a log of what the clients searched. Only the transport
 * differs between docs/examples/stdio-server.php,
 * docs/examples/http-server.php, docs/examples/react/server.php,
 * docs/examples/symfony, docs/examples/laravel and docs/examples/sdk, which
 * is the part a host writes itself or takes from an SDK or a framework.
 */

namespace Example\Notes;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;
use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;
use Novalo\McpToolDiscovery\Mode\Instructions;
use Novalo\McpToolDiscovery\Search\SearchLog;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\SearchResult;
use Novalo\McpToolDiscovery\Tool\Tool;

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
 * The mode the servers show their tools in, by the name MCP_NOTES_MODE gives.
 * Four tools would fit in a list on their own; compact is the default because
 * it is the one a large server uses, and the other three are here so each can
 * be tried on a real client with the same tools and the same gate.
 */
function mode(string|false $name): DiscoveryMode
{
    return match ($name) {
        // Every tool as itself: a role granted moves the list.
        'direct' => new DirectMode(),
        // One group holds the four tools: "notes" runs and describes the
        // reads, "notes_change" the changes, and its actions are the changes
        // the caller may see, so an admin's list names the deletion.
        'grouped' => new GroupedMode(
            groupOf: static fn(Tool $tool): string => 'notes',
            groups: ['notes' => 'Notes'],
        ),
        // One tool that searches, describes and runs. Adding a note is a
        // change every role may make, so it is marked destructive for all.
        'gateway' => new GatewayMode(prefix: 'notes_'),
        default => new CompactMode(prefix: 'notes_', batchLimit: 20),
    };
}

/**
 * The instructions of the initialize or server/discover answer: what the
 * server is, then how its mode reaches the tools it does not list. A client
 * puts them in the model's system prompt, read before any tool, so a model
 * shown three tools knows the server has more. The same for every caller,
 * so the discover answer stays public. A server whose handshake is built
 * before any caller, as the SDK's builder is, has no Discovery yet and gives
 * the mode itself.
 */
function instructions(Discovery|Instructions $from): string
{
    return trim('Notes kept for the team. ' . $from->instructions());
}

/**
 * Where the notes are kept. In memory for a process that outlives each
 * request, as over stdio or on ReactPHP; in a file when each request is a
 * process of its own, as behind PHP-FPM, or the note added by one request is
 * gone at the next.
 */
final class Notes
{
    /** @var array<int, string> */
    private array $notes = [1 => 'Back up the server before the upgrade.'];

    public function __construct(private readonly ?string $file = null)
    {
        if ($file !== null && is_file($file)) {
            $stored = json_decode((string) file_get_contents($file), true);
            if (is_array($stored)) {
                $this->notes = [];
                foreach ($stored as $id => $text) {
                    if (is_int($id) && is_string($text)) {
                        $this->notes[$id] = $text;
                    }
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->notes;
    }

    /**
     * Each note's id and the start of its text, as the list tool shows them.
     *
     * @return list<array{id: int, text: string}>
     */
    public function starts(): array
    {
        // Forty characters, not bytes: a cut inside a character would leave
        // invalid UTF-8, and no answer could be encoded.
        return array_map(
            static fn(int $id, string $text): array => ['id' => $id, 'text' => preg_match('/^.{0,40}/su', $text, $start) === 1 ? $start[0] : ''],
            array_keys($this->notes),
            array_values($this->notes),
        );
    }

    public function get(int $id): ?string
    {
        return $this->notes[$id] ?? null;
    }

    public function add(string $text): int
    {
        $this->notes[] = $text;
        $this->save();

        return (int) array_key_last($this->notes);
    }

    public function delete(int $id): bool
    {
        if (!isset($this->notes[$id])) {
            return false;
        }
        unset($this->notes[$id]);
        $this->save();

        return true;
    }

    private function save(): void
    {
        if ($this->file !== null) {
            // An example keeps it short: a real server serving many requests
            // at once uses a database, not one file rewritten whole.
            file_put_contents($this->file, json_encode($this->notes, JSON_THROW_ON_ERROR), LOCK_EX);
        }
    }
}

/**
 * Every access decision lives here, and nowhere in the library.
 */
final class NotesGate implements ToolGate
{
    private readonly NotesTools $tools;

    public function __construct(private readonly string $role, Notes $notes)
    {
        $this->tools = new NotesTools($notes);
    }

    // A tool the caller could never run is not shown at all: here only an
    // admin may delete, so anyone else never learns the tool exists.
    public function visible(Tool $tool): bool
    {
        return $tool->name !== 'notes_delete' || $this->role === 'admin';
    }

    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        return $this->tools->authorize($tool, $arguments);
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        return $this->tools->call($tool, $arguments);
    }
}

/**
 * What each tool checks in its arguments and does, whoever the caller is.
 * Apart from the gate, so a host whose framework decides who sees what (the
 * Symfony example, with a voter) keeps these rules and writes only that
 * decision.
 */
final class NotesTools
{
    public function __construct(private readonly Notes $notes) {}

    /**
     * Checked again with the arguments, at call time: the listing never saw them.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        if ($tool->name === 'notes_add' && strlen(is_string($arguments['text'] ?? null) ? $arguments['text'] : '') > 500) {
            return CallResult::error('A note holds at most 500 characters.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(Tool $tool, array $arguments): CallResult
    {
        $id = is_int($arguments['id'] ?? null) ? $arguments['id'] : 0;

        switch ($tool->name) {
            case 'notes_list':
                return CallResult::json(['notes' => $this->notes->starts()]);
            case 'notes_get':
                $text = $this->notes->get($id);

                return $text !== null ? CallResult::text($text) : CallResult::error('No note has this id.');
            case 'notes_add':
                $text = is_string($arguments['text'] ?? null) ? $arguments['text'] : '';
                if ($text === '') {
                    return CallResult::error('The text of the note is empty.');
                }

                return CallResult::json(['id' => $this->notes->add($text)]);
            case 'notes_delete':
                return $this->notes->delete($id) ? CallResult::text('Deleted.') : CallResult::error('No note has this id.');
        }

        return CallResult::error('Unknown tool.');
    }
}

/**
 * What the clients searched and ran after, one JSON object per line, so the
 * misses can be read with any tool that reads lines: a search whose found
 * list is empty, the words left unmatched, a run whose tool is not the first
 * one its search found.
 *
 * Kept only when the host names a file: the words of a request may hold
 * what a person wrote, so a server keeps them only where it chose to. No
 * caller is written, only what was asked and what ran.
 */
final class NotesSearchLog implements SearchLog
{
    public function __construct(private readonly string $file) {}

    public function searched(SearchQuery $query, SearchResult $result): void
    {
        $this->write([
            'search' => $result->searchId,
            'fields' => $query->fields(),
            'found' => array_map(static fn(Tool $tool): string => $tool->name, $result->tools),
            'unmatched' => $result->unmatched,
        ]);
    }

    public function ran(Tool $tool, bool $succeeded, ?string $searchId): void
    {
        $this->write(['search' => $searchId, 'ran' => $tool->name, 'succeeded' => $succeeded]);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function write(array $line): void
    {
        // The log is called during the client's request and an exception
        // would fail it: a line that cannot be written is lost, the call is
        // not, and a warning is silenced because over stdio PHP prints it on
        // the output the client reads. LOCK_EX keeps the lines of two
        // processes apart, as behind PHP-FPM.
        $json = json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z')] + $line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            @file_put_contents($this->file, $json . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
