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
 * pull request, with the new numbers in the README and docs/SEARCH.md, which
 * the last test holds to what the benchmark measures.
 */
final class BenchmarkTest extends TestCase
{
    public function testTheRightToolComesFirstOftenEnough(): void
    {
        $scores = Benchmark::run();

        self::assertGreaterThanOrEqual(649, $scores['fields']['first'], implode("\n", $scores['fields']['misses']));
        self::assertGreaterThanOrEqual(758, $scores['fields']['top3'], implode("\n", $scores['fields']['misses']));
    }

    public function testLearningHelpsAndNeverHurts(): void
    {
        $scores = Benchmark::run();

        self::assertGreaterThanOrEqual($scores['fields']['first'], $scores['learnedUnseen']['first'], 'what was learned must not push new requests away');
        self::assertGreaterThanOrEqual($scores['fields']['top3'], $scores['learnedUnseen']['top3']);
        self::assertGreaterThanOrEqual(710, $scores['learnedAgain']['first'], implode("\n", $scores['learnedAgain']['misses']));
    }

    public function testFieldsBeatOneSentence(): void
    {
        $scores = Benchmark::run();

        self::assertGreaterThan($scores['sentence']['first'] + 50, $scores['fields']['first']);
    }

    /**
     * The README and docs/SEARCH.md give the scores a host reads before
     * choosing the library. Each change to the search moves them, and a page
     * left behind keeps telling hosts about a benchmark that no longer exists:
     * SEARCH.md still said 202 of 250 above a table that said 362 of 449.
     */
    public function testTheDocsGiveTheScoresMeasured(): void
    {
        $scores = Benchmark::run();
        // The rows of both tables, in the order they are written.
        $measured = [];
        foreach (['fields', 'sentence', 'learnedUnseen', 'learnedAgain'] as $row) {
            $measured[] = [$scores[$row]['first'], $scores[$row]['top3']];
        }
        $queries = $scores['fields']['queries'];
        $root = \dirname(__DIR__, 2);

        foreach (['README.md', 'docs/SEARCH.md'] as $page) {
            $text = (string) file_get_contents($root . '/' . $page);
            self::assertSame(1, preg_match('/^\| Test half, (\d+) requests \|.*\n\|[-| ]+\|\n((?:\|.*\|\n){4})/m', $text, $table), $page . ': no table of the test half');
            self::assertSame((string) $queries, $table[1], $page . ': the number of test requests');
            preg_match_all('/^\|[^|]+\| \**(\d+)\** \| \**(\d+)\** \|$/m', $table[2], $rows, PREG_SET_ORDER);
            self::assertSame($measured, array_map(static fn(array $r): array => [(int) $r[1], (int) $r[2]], $rows), $page . ': the table of the test half');
        }

        $search = (string) file_get_contents($root . '/docs/SEARCH.md');
        self::assertSame(1, preg_match('/first (\d+) times\s+out of (\d+) in fields, and (\d+) times as one sentence/', $search, $sentence), 'docs/SEARCH.md: no sentence that sums the fields up');
        self::assertSame(
            [$scores['fields']['first'], $queries, $scores['sentence']['first']],
            [(int) $sentence[1], (int) $sentence[2], (int) $sentence[3]],
            'docs/SEARCH.md: the sentence that sums the fields up',
        );
    }
}
