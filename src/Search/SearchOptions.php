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

/**
 * The settings of a tool search.
 *
 * The defaults were measured on a real list of 135 tools against two sets of
 * queries, one used to choose them and one kept aside to check them: see
 * docs/SEARCH.md. Change them with a benchmark of your own, not by feel.
 */
final class SearchOptions
{
    /**
     * @param array<string, float> $weights how much a match counts in each field of a tool
     * @param array<string, string> $synonyms a word of the caller => words of the tools,
     *        e.g. ['meeting' => 'calendar event']; the host's own vocabulary, never built in
     * @param string|null $namePrefix a prefix every tool name carries, e.g. "acme_",
     *        left out of the name when matching; null to detect it
     */
    public function __construct(
        public readonly array $weights = ['name' => 3.0, 'title' => 2.0, 'description' => 1.0, 'parameters' => 0.5],
        public readonly float $trigramWeight = 0.35,
        public readonly float $verbBonus = 2.0,
        public readonly float $objectBonus = 4.0,
        public readonly float $cut = 0.6,
        public readonly int $limit = 10,
        public readonly array $synonyms = [],
        public readonly ?string $namePrefix = null,
    ) {
        if ($cut < 0 || $cut > 1) {
            throw new \InvalidArgumentException('The cut is a share of the best score, between 0 and 1.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be at least 1.');
        }
    }
}
