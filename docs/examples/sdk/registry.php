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
 * The notes server for a host whose tools already live in the SDK's registry:
 * written as handlers, added with addTool() or found by attribute discovery.
 * They stay there, as they are. The library reads their definitions from the
 * registry, and runs each one through the SDK's own CallToolHandler, so a tool
 * found by search runs exactly as a direct call would have run it: arguments
 * checked against its schema, its return value formatted, a ToolCallException
 * turned into an error result.
 *
 * Install: composer install, in this directory.
 * Run it:   php docs/examples/sdk/registry.php
 * As admin: MCP_NOTES_ROLE=admin php docs/examples/sdk/registry.php
 */

namespace Example\Notes\Sdk;

use Closure;
use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\ReferenceHandler;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Server;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Transport\StdioTransport;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require __DIR__ . '/bridge.php';

/**
 * Runs a tool of the registry through the SDK's CallToolHandler, as if the
 * client had called it by its own name. Who sees which tool stays a decision
 * of the host, given as $visible.
 */
final class RegistryGate implements ToolGate
{
    /**
     * @param Closure(Tool): bool $visible
     */
    public function __construct(
        private readonly CallToolHandler $sdk,
        private readonly Request $request,
        private readonly SessionInterface $session,
        private readonly Closure $visible,
    ) {}

    public function visible(Tool $tool): bool
    {
        return ($this->visible)($tool);
    }

    // The tools check their own arguments, as SDK handlers do: the schema
    // the SDK derived from their signature, then their own code.
    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        return null;
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        // The id and _meta of the client's request go along: the SDK reads
        // the agreed revision from them, as it would for a direct call.
        $call = (new CallToolRequest($tool->name, $arguments))
            ->withId($this->request->getId())
            ->withMeta($this->request->getMeta());
        $reply = $this->sdk->handle($call, $this->session);

        // An argument the schema refuses is a protocol error for the SDK. For
        // the assistant, it is the tool's answer: it reads it and tries again.
        if ($reply instanceof Error) {
            return CallResult::error($reply->message);
        }
        // A tool that asks the client a question needs the SDK to carry the
        // question and its answer; a mode in between cannot.
        if ($reply->result instanceof InputRequiredResult) {
            return CallResult::error('This tool asks for input the server cannot request here.');
        }

        $result = json_decode(json_encode($reply->result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $result a CallToolResult encodes as a JSON object */
        $result = is_array($result) ? $result : [];

        return CallResult::fromArray($result);
    }
}

/**
 * Answers tools/list and tools/call from the library, with the tools of the
 * SDK's registry. The SDK asks the handlers a host adds before its own, so a
 * client never reaches a tool of the registry but through the mode and the
 * gate.
 *
 * @implements RequestHandlerInterface<Answer>
 */
final class RegistryToolsHandler implements RequestHandlerInterface
{
    private ?ToolSet $tools = null;

    /**
     * @param Closure(Tool): bool $visible
     */
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly CallToolHandler $sdk,
        private readonly Closure $visible,
        private readonly SearchSetup $search,
    ) {}

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
        // The gate runs the tool with this request's session, so it is built
        // per request, and the Discovery with it. The search setup is not:
        // its cache and what it learned outlive the request.
        $discovery = new Discovery(
            new CompactMode(prefix: 'notes_', batchLimit: 20),
            new RegistryGate($this->sdk, $request, $session, $this->visible),
            $this->search,
        );

        if ($request instanceof CallToolRequest) {
            return new Response($request->getId(), new Answer($discovery->callTool($request->name, arguments($request), $this->tools(), $revision)));
        }

        return new Response($request->getId(), new Answer($discovery->listTools($this->tools(), $revision)));
    }

    // Read once the SDK has loaded the registry, and in its order. A host
    // that adds or removes tools at run time builds it again then.
    private function tools(): ToolSet
    {
        if ($this->tools === null) {
            $definitions = [];
            foreach ($this->registry->getTools() as $tool) {
                $definitions[] = json_decode(json_encode($tool, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }

            /** @var list<array<string, mixed>> $definitions each Tool encodes as a JSON object */
            $this->tools = ToolSet::of(array_map(Tool::fromArray(...), $definitions));
        }

        return $this->tools;
    }
}

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
