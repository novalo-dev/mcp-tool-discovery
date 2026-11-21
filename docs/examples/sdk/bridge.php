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
 * What the servers on the official MCP PHP SDK share: reading the revision
 * and the arguments the SDK agreed on, and handing the library's answer back
 * to it. docs/examples/sdk/server.php and docs/examples/sdk/http-server.php
 * keep their tools outside the SDK and answer them with ToolsHandler, over
 * stdio and over HTTP; docs/examples/sdk/registry.php keeps them in the SDK's
 * registry.
 */

namespace Example\Notes\Sdk;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Exception\UnsupportedRevision;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * Answers tools/list and tools/call from the library. The SDK asks the
 * handlers a host adds before its own, so its registry of tools is never
 * reached and stays empty.
 *
 * @implements RequestHandlerInterface<Answer>
 */
final class ToolsHandler implements RequestHandlerInterface
{
    public function __construct(private readonly Discovery $discovery, private readonly ToolSet $tools) {}

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

        if ($request instanceof CallToolRequest) {
            return new Response($request->getId(), new Answer($this->discovery->callTool($request->name, arguments($request), $this->tools, $revision)));
        }

        return new Response($request->getId(), new Answer($this->discovery->listTools($this->tools, $revision)));
    }
}

/**
 * A result the library already shaped for the revision. The SDK wants an
 * object it can encode; in the stateless revision it keeps the resultType,
 * ttlMs and cacheScope the answer already carries.
 */
final class Answer implements ResultInterface
{
    /**
     * @param array<string, mixed> $result
     */
    public function __construct(private readonly array $result) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->result;
    }
}

/**
 * The SDK has already agreed on the revision, through the handshake or the
 * request's _meta in the stateless revision; the library cuts its answers to
 * the same one.
 */
function revision(Request $request, SessionInterface $session): Revision
{
    $agreed = (new RequestContext($session, $request))->getProtocolVersion()->value;
    try {
        return Revision::fromString($agreed);
    } catch (UnsupportedRevision) {
        // An SDK newer than the library may agree on a revision the library
        // does not shape yet: answer in the latest one it shapes that the
        // SDK can also hold a session for.
        return Revision::latestWithHandshake();
    }
}

/**
 * The SDK keeps nested JSON objects as stdClass, so the items of a batch would
 * be refused as not objects; the library reads arguments as
 * json_decode(..., true) gives them.
 *
 * @return array<string, mixed>
 */
function arguments(CallToolRequest $request): array
{
    $arguments = json_decode(json_encode($request->arguments, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> a JSON object decoded as an array */
    return is_array($arguments) ? $arguments : [];
}
