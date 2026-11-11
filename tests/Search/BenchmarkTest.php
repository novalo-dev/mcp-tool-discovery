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
 * The floors are the scores measured on the test half, the requests kept
 * aside while the settings were chosen. A change that lowers a score below
 * its floor fails here; one that raises it should raise the floor in the same
 * pull request, with the new numbers in docs/SEARCH.md.
 */
final class BenchmarkTest extends TestCase
{
    public function testTheRightToolComesFirstOftenEnough(): void
    {
        $scores = Benchmark::run();

        self::assertGreaterThanOrEqual(125, $scores['fields']['first'], implode("\n", $scores['fields']['misses']));
        self::assertGreaterThanOrEqual(140, $scores['fields']['top3'], implode("\n", $scores['fields']['misses']));
    }

    public function testLearningHelpsAndNeverHurts(): void
    {
        $scores = Benchmark::run();

        self::assertGreaterThanOrEqual($scores['fields']['first'], $scores['learnedUnseen']['first'], 'what was learned must not push new requests away');
        self::assertGreaterThanOrEqual($scores['fields']['top3'], $scores['learnedUnseen']['top3']);
        self::assertGreaterThanOrEqual(133, $scores['learnedAgain']['first'], implode("\n", $scores['learnedAgain']['misses']));
    }

    public function testFieldsBeatOneSentence(): void
    {
        $scores = Benchmark::run();

        self::assertGreaterThan($scores['sentence']['first'] + 50, $scores['fields']['first']);
    }
}
