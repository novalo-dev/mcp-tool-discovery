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

namespace Example\Notes\Symfony;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Example\Notes\answerHttp;
use function Example\Notes\encode;
use function Example\Notes\roleOf;

/**
 * The /mcp route. It answers exactly as the other HTTP servers do, from
 * http.php; it only turns Symfony's request into what http.php reads and
 * its answer back into a Symfony response.
 */
final class McpController
{
    public function __construct(private readonly Notes $notes, private readonly SearchSetup $search) {}

    public function __invoke(Request $request): Response
    {
        $header = static fn(string $name): ?string => $request->headers->get($name);
        [$status, $body, $headers] = answerHttp(
            $request->getMethod(),
            $header,
            $request->getContent(),
            // Built inside the request, never in the container: a Discovery
            // holds the gate of one caller.
            fn(): ?Discovery => ($role = roleOf($header)) === null ? null : new Discovery(
                new CompactMode(prefix: 'notes_', batchLimit: 20),
                new NotesGate($role, $this->notes),
                $this->search,
            ),
        );
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return new Response($body === null ? '' : encode($body), $status, $headers);
    }
}
