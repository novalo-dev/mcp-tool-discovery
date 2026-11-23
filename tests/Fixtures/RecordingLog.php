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

namespace Novalo\McpToolDiscovery\Tests\Fixtures;

use Novalo\McpToolDiscovery\Search\SearchLog;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\SearchResult;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * A search log for tests: keeps what it is told, as a host would write it
 * somewhere.
 */
final class RecordingLog implements SearchLog
{
    /** @var list<array{query: SearchQuery, result: SearchResult}> */
    public array $searches = [];

    /** @var list<array{tool: string, succeeded: bool, searchId: string|null}> */
    public array $runs = [];

    public function searched(SearchQuery $query, SearchResult $result): void
    {
        $this->searches[] = ['query' => $query, 'result' => $result];
    }

    public function ran(Tool $tool, bool $succeeded, ?string $searchId): void
    {
        $this->runs[] = ['tool' => $tool->name, 'succeeded' => $succeeded, 'searchId' => $searchId];
    }
}
