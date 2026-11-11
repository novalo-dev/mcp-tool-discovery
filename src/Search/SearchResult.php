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

namespace Novalo\McpToolDiscovery\Search;

use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * What a search found, and what the caller needs to search better.
 */
final class SearchResult
{
    /**
     * @param list<Tool> $tools best first; empty when nothing matches
     * @param string|null $searchId given back to a runner with the tool chosen,
     *        so the search learns from what worked; null when nothing can be learned
     * @param list<string> $unmatched words of the query no tool uses, so the
     *        caller knows which ones to say differently
     */
    public function __construct(
        public readonly array $tools,
        public readonly ?string $searchId = null,
        public readonly array $unmatched = [],
    ) {}
}
