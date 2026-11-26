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
 * servers: the claims that hold whatever the wording, and the bytes its table
 * and the README give, since a host chooses a mode on them.
 */
final class ListCostTest extends TestCase
{
    /** @var array<string, array{tools: int, all: int, compact: int, gateway: int, grouped: ?int, search: int}>|null */
    private static ?array $rows = null;

    public function testTheCompactAndGatewayListsDoNotGrowWithTheServer(): void
    {
        $rows = self::rows();

        self::assertCount(1, array_unique(array_column($rows, 'compact')));
        self::assertCount(1, array_unique(array_column($rows, 'gateway')));
    }

    public function testTheGatewayListIsTheSmallestAndTheCompactOneComesNext(): void
    {
        foreach (self::rows() as $server => $row) {
            self::assertLessThan($row['compact'], $row['gateway'], $server);
            self::assertLessThan($row['all'] / 4, $row['compact'], $server);
        }
    }

    public function testTheListAndOneSearchCostLessThanTheWholeList(): void
    {
        // A client that searches once per task still sends less than the
        // whole list would, and the list is sent again with every request.
        foreach (self::rows() as $server => $row) {
            self::assertLessThan($row['all'] / 2, $row['compact'] + $row['search'], $server);
        }
    }

    public function testTheGroupedListIsNoLargerThanTheWholeList(): void
    {
        $workspace = self::rows()['workspace'];

        self::assertNotNull($workspace['grouped']);
        self::assertLessThan($workspace['all'], $workspace['grouped']);
    }

    /**
     * Every change to the search moves the bytes of its answer, and nobody
     * refreshed the table by hand: seven servers had drifted, the kindergarten
     * by 216 bytes. The failure shows the table as measured, ready to paste.
     */
    public function testTheDocsGiveTheBytesMeasured(): void
    {
        $rows = self::rows();
        $measured = '';
        foreach ($rows as $server => $row) {
            $cells = [$server, (string) $row['tools'], number_format($row['all']), number_format($row['compact']), number_format($row['gateway']), null === $row['grouped'] ? '-' : number_format($row['grouped']), number_format($row['search'])];
            $measured .= '| ' . implode(' | ', $cells) . " |\n";
        }
        $root = \dirname(__DIR__, 2);

        $modes = (string) file_get_contents($root . '/docs/MODES.md');
        self::assertSame(1, preg_match('/^\| Server \| Tools \|.*\n\|[-| ]+\|\n((?:\|.*\|\n)+)/m', $modes, $table), 'docs/MODES.md: no table of what each list costs');
        self::assertStringContainsString('JSON on `' . ListCost::REVISION . '`', $modes, 'docs/MODES.md: the revision measured');
        self::assertSame($measured, $table[1], 'docs/MODES.md: the table of what each list costs');

        // The README names the largest server, the one where the gap shows most.
        uasort($rows, static fn(array $a, array $b): int => $b['tools'] <=> $a['tools']);
        $largest = array_key_first($rows);
        $readme = (string) file_get_contents($root . '/README.md');
        self::assertSame(1, preg_match('/on the one with (\d+) tools, ([\d,]+) bytes\s+for every tool as itself, ([\d,]+) for the compact list/', $readme, $sentence), 'README.md: no sentence on what the lists cost');
        self::assertSame(
            [(string) $rows[$largest]['tools'], number_format($rows[$largest]['all']), number_format($rows[$largest]['compact'])],
            [$sentence[1], $sentence[2], $sentence[3]],
            'README.md: the sentence on what the lists cost',
        );
    }

    /**
     * The sentence on the grouped mode is what a host weighs it on, and the
     * table cannot hold it: the group tools are only part of the grouped list,
     * whose other tools are listed as themselves.
     */
    public function testTheDocsGiveTheBytesOfTheGroupTools(): void
    {
        $groups = ListCost::groups();
        $modes = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MODES.md');

        self::assertSame(1, preg_match('/On the workspace its (\d+) tools take ([\d,]+) bytes where the (\d+)\s+actions they stand for would take ([\d,]+): on short tools it saves almost a\s+quarter/', $modes, $sentence), 'docs/MODES.md: no sentence on what the group tools cost');
        self::assertSame(
            [(string) $groups['tools'], number_format($groups['bytes']), (string) $groups['actions'], number_format($groups['actionBytes'])],
            [$sentence[1], $sentence[2], $sentence[3], $sentence[4]],
            'docs/MODES.md: the sentence on what the group tools cost',
        );
        // "Almost a quarter": more than a fifth saved, not yet a quarter.
        self::assertGreaterThan(0.75, $groups['bytes'] / $groups['actionBytes']);
        self::assertLessThan(0.8, $groups['bytes'] / $groups['actionBytes']);
    }

    /**
     * The line on Direct is where a host with a small server decides, so the
     * count it gives is measured, not guessed: how many tools, at each
     * server's own mean size, weigh as much as the compact list alone and
     * as the list with one search. Rounded, the range over every server.
     */
    public function testTheDocsSayFromHowManyToolsDirectCostsMore(): void
    {
        $alone = [];
        $withSearch = [];
        foreach (self::rows() as $row) {
            $perTool = $row['all'] / $row['tools'];
            $alone[] = $row['compact'] / $perTool;
            $withSearch[] = ($row['compact'] + $row['search']) / $perTool;
        }
        $modes = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MODES.md');

        self::assertSame(1, preg_match('/the compact list alone weighs as much as (\d+)\s+to (\d+) tools listed as themselves, and (\d+) to (\d+) with one search: under about\s+(\w+) tools, Direct sends less/', $modes, $sentence), 'docs/MODES.md: no sentence on when Direct sends less');
        self::assertSame(
            [(int) round(min($alone)), (int) round(max($alone)), (int) round(min($withSearch)), (int) round(max($withSearch))],
            [(int) $sentence[1], (int) $sentence[2], (int) $sentence[3], (int) $sentence[4]],
            'docs/MODES.md: the sentence on when Direct sends less',
        );
        // "About ten" has to sit inside the range measured with one search.
        self::assertSame('ten', $sentence[5]);
        self::assertLessThanOrEqual(10, min($withSearch));
        self::assertGreaterThanOrEqual(10, max($withSearch));
    }

    /**
     * @return array<string, array{tools: int, all: int, compact: int, gateway: int, grouped: ?int, search: int}>
     */
    private static function rows(): array
    {
        // One measure for the whole class: it runs every test request on every
        // server, seconds each time, and nothing it reads changes in between.
        return self::$rows ??= ListCost::run();
    }
}
