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

namespace Novalo\McpToolDiscovery\Tests\Search;

use Novalo\McpToolDiscovery\Tests\Fixtures\ListCost;
use PHPUnit\Framework\TestCase;

/**
 * Guards what docs/MODES.md says each mode costs, measured on the benchmark
 * servers. The claims, not the bytes: a reworded description moves the
 * numbers, and the table in the docs is refreshed from bin/benchmark.php.
 */
final class ListCostTest extends TestCase
{
    public function testTheCompactAndGatewayListsDoNotGrowWithTheServer(): void
    {
        $rows = ListCost::run();

        self::assertCount(1, array_unique(array_column($rows, 'compact')));
        self::assertCount(1, array_unique(array_column($rows, 'gateway')));
    }

    public function testTheGatewayListIsTheSmallestAndTheCompactOneComesNext(): void
    {
        foreach (ListCost::run() as $server => $row) {
            self::assertLessThan($row['compact'], $row['gateway'], $server);
            self::assertLessThan($row['all'] / 4, $row['compact'], $server);
        }
    }

    public function testTheListAndOneSearchCostLessThanTheWholeList(): void
    {
        // A client that searches once per task still sends less than the
        // whole list would, and the list is sent again with every request.
        foreach (ListCost::run() as $server => $row) {
            self::assertLessThan($row['all'] / 2, $row['compact'] + $row['search'], $server);
        }
    }

    public function testTheGroupedListIsNoLargerThanTheWholeList(): void
    {
        $workspace = ListCost::run()['workspace'];

        self::assertNotNull($workspace['grouped']);
        self::assertLessThan($workspace['all'], $workspace['grouped']);
    }
}
