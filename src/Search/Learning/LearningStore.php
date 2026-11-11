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

namespace Novalo\McpToolDiscovery\Search\Learning;

/**
 * Remembers which tool was run after a search, word by word.
 *
 * Only words the tools themselves use reach a store: ToolSearch drops the
 * others before recording, so a store never holds a name, an address or
 * anything else a person typed in a request.
 */
interface LearningStore
{
    /**
     * @return array<string, array<string, int>> word => tool name => successful runs
     */
    public function load(): array;

    /**
     * @param list<string> $words the words of the search, as the Analyzer cuts them
     * @param string $tool the tool run with success after that search
     */
    public function record(array $words, string $tool): void;
}
