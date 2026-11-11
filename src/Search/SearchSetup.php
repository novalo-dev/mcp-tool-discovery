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

use Novalo\McpToolDiscovery\Search\Cache\IndexCache;
use Novalo\McpToolDiscovery\Search\Learning\LearningStore;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * How the host wants its tools searched, whatever the mode.
 *
 * Given once to Discovery, not to a mode: a server that changes modes keeps
 * its settings, its cache and what it learned, and a mode never has to wire
 * them itself. The cache and the learning store are both optional; without
 * them the index is built per request and nothing is learned.
 */
final class SearchSetup
{
    /**
     * @param IndexCache|null $cache keeps the index between requests
     * @param LearningStore|null $learning learns which tool answered which words; none by default
     */
    public function __construct(
        public readonly SearchOptions $options = new SearchOptions(),
        public readonly ?IndexCache $cache = null,
        public readonly ?LearningStore $learning = null,
    ) {}

    /**
     * A search over these tools, the ones the caller may see.
     */
    public function searchFor(ToolSet $tools): ToolSearch
    {
        return new ToolSearch($tools, $this->options, $this->cache, $this->learning);
    }

    public function learns(): bool
    {
        return $this->learning !== null && $this->options->learningWeight > 0;
    }
}
