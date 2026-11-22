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
 * What the two servers whose tools stay in the SDK's registry share: reading
 * the definitions from the registry, and running each tool through the SDK's
 * own CallToolHandler. docs/examples/sdk/registry.php registers its tools with
 * addTool(); docs/examples/sdk/attributes.php lets the SDK find them by their
 * #[McpTool] attribute. The registry reads the same either way.
 */

namespace Example\Notes\Sdk;

use Closure;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

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
     * @param int<1, max> $pageSize the most tools one tools/list answer holds;
     *        50 is the SDK's own setPaginationLimit() default, so its other
     *        lists and this one page alike
     */
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly CallToolHandler $sdk,
        private readonly Closure $visible,
        private readonly SearchSetup $search,
        private readonly int $pageSize = 50,
    ) {}

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest || $request instanceof CallToolRequest;
    }

    /**
     * @return Response<Answer>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
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

        \assert($request instanceof ListToolsRequest);

        return page($discovery, $this->tools(), $request, $revision, $this->pageSize);
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
