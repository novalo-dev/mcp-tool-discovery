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

use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\ToolSearch;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * The search benchmark: 300 requests over three servers named in three styles.
 *
 * Each request is written twice, as one sentence (query) and in fields
 * (action, object, description), with the tool a person would expect. Half
 * of them (train) were used to choose the settings; the other half (test)
 * was kept aside and is the score that counts.
 *
 * Shared by the test that guards the floors and by bin/benchmark.php, which
 * prints the score and writes its badge, so both measure the same thing.
 */
final class Benchmark
{
    /** The server each request is asked of => its fixture file. */
    private const SERVERS = ['workspace' => 'tools', 'platform' => 'platform', 'people' => 'people'];

    /**
     * Four measures on the test half:
     * - fields: the requests in fields, nothing learned;
     * - sentence: the same requests as one sentence;
     * - learnedUnseen: in fields, after every train request was run once
     *   with its expected tool, so what was learned meets new words;
     * - learnedAgain: in fields, after the test requests themselves were
     *   run once, as when people ask the same thing again.
     *
     * @return array<string, array{queries: int, first: int, top3: int, misses: list<string>}>
     */
    public static function run(SearchOptions $options = new SearchOptions()): array
    {
        $queries = self::queries();
        $servers = array_map([RecordingGate::class, 'server'], self::SERVERS);
        $test = array_values(array_filter($queries, static fn(array $case): bool => $case['half'] === 'test'));
        $train = array_values(array_filter($queries, static fn(array $case): bool => $case['half'] === 'train'));

        return [
            'fields' => self::score($servers, $options, $test, false, []),
            'sentence' => self::score($servers, $options, $test, true, []),
            'learnedUnseen' => self::score($servers, $options, $test, false, $train),
            'learnedAgain' => self::score($servers, $options, $test, false, $test),
        ];
    }

    /**
     * @param array<string, ToolSet> $servers
     * @param list<array{server: string, half: string, action: string, object: string, description: string, query: string, expected: string}> $cases
     * @param list<array{server: string, half: string, action: string, object: string, description: string, query: string, expected: string}> $learnFrom
     *
     * @return array{queries: int, first: int, top3: int, misses: list<string>}
     */
    private static function score(array $servers, SearchOptions $options, array $cases, bool $sentence, array $learnFrom): array
    {
        $cache = new MemoryIndexCache();
        $stores = array_map(static fn(): MemoryLearningStore => new MemoryLearningStore(), $servers);
        $search = static fn(string $server): ToolSearch => new ToolSearch($servers[$server], $options, $cache, $learnFrom === [] ? null : $stores[$server]);
        foreach ($learnFrom as $case) {
            $tools = $search($case['server']);
            $id = $tools->find(self::fields($case))->searchId;
            if ($id !== null) {
                $tools->learn($id, $case['expected']);
            }
        }
        $first = 0;
        $top3 = 0;
        $misses = [];
        foreach ($cases as $case) {
            $query = $sentence ? SearchQuery::text($case['query']) : self::fields($case);
            $names = array_map(static fn(Tool $tool): string => $tool->name, $search($case['server'])->search($query));
            if (($names[0] ?? null) === $case['expected']) {
                ++$first;
            } else {
                $misses[] = sprintf('%s "%s": expected %s, got %s', $case['server'], $sentence ? $case['query'] : implode(' | ', [$case['action'], $case['object'], $case['description']]), $case['expected'], implode(', ', array_slice($names, 0, 3)) ?: 'nothing');
            }
            if (in_array($case['expected'], array_slice($names, 0, 3), true)) {
                ++$top3;
            }
        }

        return ['queries' => count($cases), 'first' => $first, 'top3' => $top3, 'misses' => $misses];
    }

    /**
     * @param array{action: string, object: string, description: string} $case
     */
    private static function fields(array $case): SearchQuery
    {
        return new SearchQuery($case['action'], $case['object'], $case['description']);
    }

    /**
     * @return list<array{server: string, half: string, action: string, object: string, description: string, query: string, expected: string}>
     */
    private static function queries(): array
    {
        $json = file_get_contents(__DIR__ . '/queries.json');
        if ($json === false) {
            throw new \RuntimeException('tests/Fixtures/queries.json is missing.');
        }
        /** @var list<array{server: string, half: string, action: string, object: string, description: string, query: string, expected: string}> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
