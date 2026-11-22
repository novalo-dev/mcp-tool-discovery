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

namespace Example\Notes\Sdk\Tools;

use Example\Notes\Notes;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Exception\ToolCallException;

/**
 * The four note tools, as a server written for the SDK often holds them: the
 * methods of a class, each marked #[McpTool], found by the SDK's discovery.
 * The SDK takes the description from the doc comment and the schema from the
 * signature; nothing here knows the library is in between.
 */
final class NoteTools
{
    // Built by the SDK's container, which hands it the server's one Notes.
    public function __construct(private readonly Notes $notes) {}

    /**
     * Lists every note, with its id and the start of its text.
     *
     * @return array{notes: list<array{id: int, text: string}>}
     */
    #[McpTool(name: 'notes_list', title: 'List the notes', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(): array
    {
        return ['notes' => $this->notes->starts()];
    }

    /**
     * Returns the full text of one note.
     *
     * @param int $id the id of the note, as the list gives it
     */
    #[McpTool(name: 'notes_get', title: 'Read a note', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(int $id): string
    {
        return $this->notes->get($id) ?? throw new ToolCallException('No note has this id.');
    }

    /**
     * Adds a note and returns its id.
     *
     * @param string $text the text of the note, at most 500 characters
     *
     * @return array{id: int}
     */
    #[McpTool(name: 'notes_add', title: 'Add a note')]
    public function add(string $text): array
    {
        if ($text === '') {
            throw new ToolCallException('The text of the note is empty.');
        }
        if (strlen($text) > 500) {
            throw new ToolCallException('A note holds at most 500 characters.');
        }

        return ['id' => $this->notes->add($text)];
    }

    /**
     * Deletes one note for good.
     *
     * @param int $id the id of the note to delete
     */
    #[McpTool(name: 'notes_delete', title: 'Delete a note', annotations: new ToolAnnotations(destructiveHint: true))]
    public function delete(int $id): string
    {
        return $this->notes->delete($id) ? 'Deleted.' : throw new ToolCallException('No note has this id.');
    }
}
