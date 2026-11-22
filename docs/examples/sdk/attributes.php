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
 * The notes server for a host whose tools are methods marked #[McpTool], found
 * by the SDK's attribute discovery (docs/examples/sdk/tools/NoteTools.php).
 * They stay in the SDK's registry and run through its CallToolHandler, as in
 * docs/examples/sdk/registry.php; only the way they reach the registry
 * differs.
 *
 * Install: composer install, in this directory.
 * Run it:   php docs/examples/sdk/attributes.php
 * As admin: MCP_NOTES_ROLE=admin php docs/examples/sdk/attributes.php
 */

namespace Example\Notes\Sdk;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\Container;
use Mcp\Server\Capability\Registry\ReferenceHandler;
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

// The SDK builds the class of the tools through this container, so every
// tool works on the same notes. The handler of references below must be
// given it too: the one the SDK would build is out of reach, and a handler
// without the container would build the class with no Notes.
$container = new Container();
$container->set(Notes::class, $notes);
$referenceHandler = new ReferenceHandler($container);

$server = Server::builder()
    ->setServerInfo('notes', '1.0.0')
    ->setContainer($container)
    ->setRegistry($registry)
    ->setReferenceHandler($referenceHandler)
    // Discovery reads the classes Composer autoloads from tools/; see the
    // autoload section of this directory's composer.json.
    ->setDiscovery(__DIR__, ['tools'])
    ->addRequestHandler(new RegistryToolsHandler(
        $registry,
        new CallToolHandler($registry, $referenceHandler),
        (new NotesGate(is_string($role) ? $role : 'reader', $notes))->visible(...),
        new SearchSetup(cache: new MemoryIndexCache(), learning: new MemoryLearningStore()),
    ))
    ->build();

$server->run(new StdioTransport());
