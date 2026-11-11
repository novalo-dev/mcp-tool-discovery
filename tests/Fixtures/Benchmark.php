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

use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\ToolSearch;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * The search benchmark: the fixture queries against the fixture tools.
 *
 * Shared by the test that guards the floors and by bin/benchmark.php, which
 * prints the score and writes its badge, so both measure the same thing.
 */
final class Benchmark
{
    /**
     * @return array{queries: int, first: int, top3: int, misses: list<string>}
     */
    public static function run(SearchOptions $options = new SearchOptions()): array
    {
        $json = file_get_contents(__DIR__ . '/queries.json');
        if ($json === false) {
            throw new \RuntimeException('tests/Fixtures/queries.json is missing.');
        }
        /** @var list<array{query: string, expected: string}> $queries */
        $queries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $search = new ToolSearch(RecordingGate::fixtureTools(), $options);
        $first = 0;
        $top3 = 0;
        $misses = [];
        foreach ($queries as $case) {
            $names = array_map(static fn(Tool $tool): string => $tool->name, $search->search($case['query']));
            if (($names[0] ?? null) === $case['expected']) {
                ++$first;
            } else {
                $misses[] = sprintf('"%s": expected %s, got %s', $case['query'], $case['expected'], implode(', ', array_slice($names, 0, 3)) ?: 'nothing');
            }
            if (in_array($case['expected'], array_slice($names, 0, 3), true)) {
                ++$top3;
            }
        }

        return ['queries' => count($queries), 'first' => $first, 'top3' => $top3, 'misses' => $misses];
    }
}
