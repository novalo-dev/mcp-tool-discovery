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

namespace Example\Notes\LaravelMcp;

use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Enums\RequestHeader;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Exception\UnsupportedRevision;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * What the two methods share: the Discovery of one request, built from the
 * tools laravel/mcp keeps for it, and the revision that request speaks.
 */
abstract class DiscoveryMethod implements Method
{
    // The container builds each method for each message, so the shared
    // SearchSetup (its index cache) is injected and nothing of one caller
    // outlives the request.
    public function __construct(private readonly SearchSetup $search) {}

    /**
     * @return array{Discovery, ToolSet}
     */
    protected function discovery(JsonRpcRequest $request, ServerContext $context): array
    {
        [$tools, $gate] = ContextGate::of($context, $request);

        return [new Discovery(new CompactMode(prefix: 'notes_'), $gate, $this->search), $tools];
    }

    protected static function revision(JsonRpcRequest $request, ServerContext $context): Revision
    {
        // 2026-07-28 names its revision in every request; laravel/mcp has
        // already checked that it supports it.
        $named = $request->meta()[MetaKey::PROTOCOL_VERSION->value] ?? null;
        if (!is_string($named)) {
            // Before it, HTTP sends the header from 2025-06-18 on, and a
            // request without one is 2025-03-26, as the specification says.
            // Over stdio there is neither: laravel/mcp keeps no session, so
            // the newest revision with a handshake is the closest guess, and
            // a client ignores the fields it does not know.
            $named = $context->headers !== null
                ? ($context->headers->get(RequestHeader::PROTOCOL_VERSION->value) ?? '2025-03-26')
                : Revision::latestWithHandshake()->value;
        }
        try {
            return Revision::fromString($named);
        } catch (UnsupportedRevision) {
            return Revision::latestWithHandshake();
        }
    }
}
