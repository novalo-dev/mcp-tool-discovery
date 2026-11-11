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
 * The defaults were chosen on 150 queries over three servers named in three
 * styles, then checked on 150 others kept aside: see docs/SEARCH.md. Change
 * them with a benchmark of your own, not by feel.
 */
final class SearchOptions
{
    /**
     * @param array<string, float> $weights how much a match counts in each field of a tool:
     *        name, title, description, parameters
     * @param float $trigramWeight how much trigram matches count against whole-word matches
     * @param array<string, float> $queryWeights how much each field of a query counts:
     *        action, object, description, text
     * @param float $cut tools below this share of the best score are left out
     * @param int $limit the most tools a search returns
     * @param array<string, string> $synonyms a word of the caller => words of the tools,
     *        e.g. ['meeting' => 'calendar event']; the host's own vocabulary, never built in
     * @param list<string> $stopWords words to ignore, none by default
     * @param float $learningWeight how far what was learned may lift a tool, as a share of
     *        the best score; 0 turns learning off even with a store
     * @param int $learningCap the successes after which a word lifts a tool no further
     */
    public function __construct(
        public readonly array $weights = ['name' => 1.0, 'title' => 2.0, 'description' => 1.0, 'parameters' => 0.5],
        public readonly float $trigramWeight = 0.6,
        public readonly array $queryWeights = ['action' => 0.5, 'object' => 1.0, 'description' => 1.0, 'text' => 1.0],
        public readonly float $cut = 0.6,
        public readonly int $limit = 10,
        public readonly array $synonyms = [],
        public readonly array $stopWords = [],
        public readonly float $learningWeight = 0.75,
        public readonly int $learningCap = 5,
    ) {
        if ($cut < 0 || $cut > 1) {
            throw new \InvalidArgumentException('The cut is a share of the best score, between 0 and 1.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be at least 1.');
        }
        if ($learningWeight < 0 || $learningCap < 1) {
            throw new \InvalidArgumentException('The learning weight cannot be negative and the cap must be at least 1.');
        }
    }

    /**
     * What decides the index, so a cache keeps one index per setting.
     */
    public function indexKey(): string
    {
        return serialize([$this->weights, $this->trigramWeight, $this->stopWords]);
    }
}
