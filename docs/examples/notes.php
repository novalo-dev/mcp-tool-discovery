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
 * and the gate. Only the transport differs between
 * docs/examples/stdio-server.php, docs/examples/http-server.php and
 * docs/examples/sdk/server.php, which is the part a host writes itself or
 * takes from an SDK.
 */

namespace Example\Notes;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
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
 * Where the notes are kept. In memory for a process that lives as long as its
 * client, as over stdio; in a file when each request is a process of its own,
 * as over HTTP, or the note added by one request is gone at the next.
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
    public function __construct(private readonly string $role, private readonly Notes $notes) {}

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
                $notes = $this->notes->all();

                // Forty characters, not bytes: a cut inside a character
                // would leave invalid UTF-8, and no answer could be encoded.
                return CallResult::json(['notes' => array_map(
                    static fn(int $id, string $text): array => ['id' => $id, 'text' => preg_match('/^.{0,40}/su', $text, $start) === 1 ? $start[0] : ''],
                    array_keys($notes),
                    array_values($notes),
                )]);
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
