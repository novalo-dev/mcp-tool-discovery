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

use Novalo\McpToolDiscovery\Tests\Fixtures\Benchmark;
use PHPUnit\Framework\TestCase;

/**
 * Guards the search against getting worse.
 *
 * The floors are the scores measured when the queries were first written,
 * before anything was tuned on them. A change that lowers a score below its
 * floor fails here; one that raises it should raise the floor in the same
 * pull request, with the new numbers in docs/SEARCH.md.
 */
final class BenchmarkTest extends TestCase
{
    public function testTheRightToolComesFirstOftenEnough(): void
    {
        $score = Benchmark::run();

        self::assertGreaterThanOrEqual(26, $score['first'], implode("\n", $score['misses']));
        self::assertGreaterThanOrEqual(27, $score['top3'], implode("\n", $score['misses']));
    }
}
