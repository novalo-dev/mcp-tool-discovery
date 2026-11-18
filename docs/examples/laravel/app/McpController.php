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

namespace Example\Notes\Laravel;

use Example\Notes\NotesTools;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\SearchSetup;

use function Example\Notes\answerHttp;
use function Example\Notes\encode;

/**
 * The MCP endpoint: the Streamable HTTP transport of the other HTTP
 * servers, with the caller found by the mcp guard.
 */
final class McpController
{
    public function __construct(
        private readonly Gate $gate,
        private readonly NotesTools $tools,
        private readonly SearchSetup $search,
    ) {}

    public function __invoke(Request $request): Response
    {
        [$status, $body, $headers] = answerHttp(
            $request->getMethod(),
            static fn(string $name): ?string => $request->headers->get($name),
            $request->getContent(),
            // Built inside the request, never in the container: a Discovery
            // holds the gate of one caller. The guard is asked here, so the
            // Origin and the method are checked before the token.
            function () use ($request): ?Discovery {
                $user = $request->user('mcp');

                return $user instanceof Authenticatable ? new Discovery(
                    new CompactMode(prefix: 'notes_', batchLimit: 20),
                    new AbilityGate($this->gate->forUser($user), $this->tools),
                    $this->search,
                ) : null;
            },
        );
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return new Response($body === null ? '' : encode($body), $status, $headers);
    }
}
