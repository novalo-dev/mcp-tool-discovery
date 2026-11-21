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
 * The notes server for a host whose tools already live in the SDK's registry,
 * written as handlers and added with addTool(). They stay there, as they are.
 * The library reads their definitions from the registry, and runs each one
 * through the SDK's own CallToolHandler, so a tool found by search runs
 * exactly as a direct call would have run it: arguments checked against its
 * schema, its return value formatted, a ToolCallException turned into an
 * error result. docs/examples/sdk/attributes.php does the same with tools the
 * SDK finds by their #[McpTool] attribute.
 *
 * Install: composer install, in this directory.
 * Run it:   php docs/examples/sdk/registry.php
 * As admin: MCP_NOTES_ROLE=admin php docs/examples/sdk/registry.php
 */

namespace Example\Notes\Sdk;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\ReferenceHandler;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require __DIR__ . '/bridge.php';
require __DIR__ . '/registry-bridge.php';

$role = getenv('MCP_NOTES_ROLE');
$notes = new Notes();
$registry = new Registry();
$referenceHandler = new ReferenceHandler();

$server = Server::builder()
    ->setServerInfo('notes', '1.0.0')
    // The host's registry, so the handler below reads the same tools the SDK
    // registered, and the same handler of references runs them.
    ->setRegistry($registry)
    ->setReferenceHandler($referenceHandler)
    ->addTool(
        static fn(): array => ['notes' => $notes->starts()],
        name: 'notes_list',
        title: 'List the notes',
        description: 'Lists every note, with its id and the start of its text.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )
    ->addTool(
        static fn(int $id): string => $notes->get($id) ?? throw new ToolCallException('No note has this id.'),
        name: 'notes_get',
        title: 'Read a note',
        description: 'Returns the full text of one note.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )
    ->addTool(
        static function (string $text) use ($notes): array {
            if ($text === '') {
                throw new ToolCallException('The text of the note is empty.');
            }
            if (strlen($text) > 500) {
                throw new ToolCallException('A note holds at most 500 characters.');
            }

            return ['id' => $notes->add($text)];
        },
        name: 'notes_add',
        title: 'Add a note',
        description: 'Adds a note and returns its id.',
    )
    ->addTool(
        static fn(int $id): string => $notes->delete($id) ? 'Deleted.' : throw new ToolCallException('No note has this id.'),
        name: 'notes_delete',
        title: 'Delete a note',
        description: 'Deletes one note for good.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )
    ->addRequestHandler(new RegistryToolsHandler(
        $registry,
        new CallToolHandler($registry, $referenceHandler),
        // The same rule as the other examples: only an admin sees deletion.
        (new NotesGate(is_string($role) ? $role : 'reader', $notes))->visible(...),
        // The index is built at the first search instead of at every one. The
        // process serves one caller, so what its runs teach the search lifts
        // only that caller's tools, for as long as the session lasts.
        new SearchSetup(cache: new MemoryIndexCache(), learning: new MemoryLearningStore()),
    ))
    ->build();

$server->run(new StdioTransport());
