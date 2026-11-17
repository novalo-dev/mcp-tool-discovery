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

use Novalo\McpToolDiscovery\Search\Analyzer;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\Counts;
use Novalo\McpToolDiscovery\Search\Learning\FileLearningStore;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\ToolIndex;
use Novalo\McpToolDiscovery\Search\ToolSearch;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\TestCase;

final class ToolSearchTest extends TestCase
{
    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, $tools);
    }

    private static function temporary(string $name): string
    {
        return sys_get_temp_dir() . '/mcp-tool-discovery-' . $name . '-' . bin2hex(random_bytes(6));
    }

    public function testTheAnalyzerSplitsEveryNamingStyle(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['set', 'ticket', 'status'], $analyzer->words('set_ticket_status'));
        self::assertSame(['set', 'ticket', 'status'], $analyzer->words('setTicketStatus'));
        self::assertSame(['people', 'leave', 'approve'], $analyzer->words('people.leave.approve'));
        self::assertSame(['get', 'html', 'page'], $analyzer->words('getHTMLPage'));
    }

    public function testTheAnalyzerHoldsNoListOfItsOwn(): void
    {
        self::assertSame(['close', 'the', 'ticket'], (new Analyzer())->words('Close the ticket 1042'), 'numbers left out, every word kept');
        self::assertSame(['close', 'ticket'], (new Analyzer(['the']))->words('Close the ticket'), 'stop words only when the host gives them');
        self::assertContains('statut', (new Analyzer())->words('Changer le statut'), 'any language');
    }

    public function testTrigramsAreCutByCharacterInEveryScript(): void
    {
        self::assertSame([' st', 'sta', 'tat', 'atu', 'tus', 'us '], Analyzer::trigrams('status'), 'ASCII, cut by bytes');
        self::assertSame([' pr', 'prê', 'rêt', 'êt '], Analyzer::trigrams('prêt'), 'a letter outside ASCII stays whole');
        self::assertSame([' ab', 'ab '], Analyzer::trigrams('ab'));
        self::assertSame([], Analyzer::trigrams(''));
    }

    public function testTheFieldsOfAQueryFindTheRightTool(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools());

        self::assertSame('acme_set_ticket_status', $search->search(new SearchQuery('change', 'ticket status', 'move a ticket to done'))[0]->name);
        self::assertSame('acme_rollback_deployment', $search->search(new SearchQuery('roll back', 'deployment'))[0]->name);
        self::assertSame('acme_list_backups', $search->search(new SearchQuery('list', 'backups'))[0]->name);
    }

    public function testNoNamingConventionIsAssumed(): void
    {
        $camel = new ToolSearch(RecordingGate::server('platform'));
        $dotted = new ToolSearch(RecordingGate::server('people'));

        self::assertSame('archiveRepository', $camel->search(new SearchQuery('archive', 'repository'))[0]->name);
        self::assertSame('people.hire', $dotted->search(new SearchQuery('add', 'new employee', 'someone joins the company'))[0]->name);
    }

    public function testFarWeakerMatchesAreCut(): void
    {
        $found = (new ToolSearch(RecordingGate::fixtureTools()))->search('webhooks');

        self::assertLessThanOrEqual(5, count($found), 'not every tool that mentions an event');
        self::assertContains('acme_list_webhooks', self::names($found));
    }

    public function testTheLimitHolds(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools(), new SearchOptions(cut: 0.0, limit: 3));

        self::assertCount(3, $search->search('ticket'));
        self::assertCount(1, $search->search('ticket', 1));
    }

    public function testALongRequestIsReadOnlyToItsStart(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools());
        $padded = 'webhooks' . str_repeat(' ', 2000) . 'tickets';

        self::assertSame(self::names($search->search('webhooks')), self::names($search->search($padded)), 'words far past the start are not read');
        $result = $search->find(SearchQuery::text('webhooks ' . str_repeat('é', 1000)));
        self::assertTrue(preg_match('//u', implode(' ', $result->unmatched)) === 1, 'the cut falls between two characters');
    }

    public function testAHugeRequestCostsLittleMemory(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools());
        $peak = memory_get_peak_usage();
        $search->find(new SearchQuery(str_repeat('a', 2_000_000), str_repeat('ab ', 700_000), str_repeat('é', 1_000_000), str_repeat('x', 2_000_000)));

        // The request itself is about 8 MB; reading it must not cost much more.
        self::assertLessThan($peak + 16_000_000, memory_get_peak_usage());
    }

    public function testNothingMatchesNothing(): void
    {
        $result = (new ToolSearch(RecordingGate::fixtureTools()))->find(SearchQuery::text('zzzz qqqq'));

        self::assertSame([], $result->tools);
        self::assertSame(['zzzz', 'qqqq'], $result->unmatched);
        self::assertSame([], (new ToolSearch(RecordingGate::fixtureTools()))->search(new SearchQuery()));
    }

    public function testTheSameQueryAlwaysGivesTheSameOrder(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools(), new SearchOptions(cut: 0.0));

        self::assertSame(self::names($search->search('delete')), self::names($search->search('delete')));
    }

    public function testHostSynonymsBringTheHostVocabulary(): void
    {
        $tools = RecordingGate::fixtureTools();
        $query = new SearchQuery('create', 'meeting');

        self::assertNotSame('acme_create_event', (new ToolSearch($tools))->search($query)[0]->name ?? null);
        self::assertSame('acme_create_event', (new ToolSearch($tools, new SearchOptions(synonyms: ['meeting' => 'calendar event'])))->search($query)[0]->name);
    }

    public function testTheVocabularyComesFromTheToolsThemselves(): void
    {
        $vocabulary = (new ToolSearch(RecordingGate::fixtureTools()))->vocabulary();

        self::assertNotSame([], $vocabulary);
        self::assertContains('ticket', $vocabulary);
        foreach ($vocabulary as $word) {
            self::assertTrue(RecordingGate::fixtureTools()->filter(static fn(Tool $tool): bool => str_contains(strtolower($tool->title()), $word))->names() !== [], $word . ' is in a title');
        }
    }

    public function testACachedIndexAnswersLikeAFreshOne(): void
    {
        $tools = RecordingGate::fixtureTools();
        $query = new SearchQuery('change', 'ticket status');
        $fresh = self::names((new ToolSearch($tools))->search($query));
        $cache = new MemoryIndexCache();
        new ToolSearch($tools, cache: $cache);

        self::assertSame($fresh, self::names((new ToolSearch($tools, cache: $cache))->search($query)));

        $directory = self::temporary('cache');
        new ToolSearch($tools, cache: new FileIndexCache($directory));
        self::assertCount(1, glob($directory . '/*.php') ?: []);
        self::assertSame($fresh, self::names((new ToolSearch($tools, cache: new FileIndexCache($directory)))->search($query)));
        array_map('unlink', glob($directory . '/*.php') ?: []);
        rmdir($directory);
    }

    public function testEachVisibleSetHasItsOwnIndex(): void
    {
        $cache = new MemoryIndexCache();
        $all = RecordingGate::fixtureTools();
        $fewer = $all->filter(static fn(Tool $tool): bool => $tool->name !== 'acme_set_ticket_status');
        new ToolSearch($all, cache: $cache);

        // Built over the tools this caller may see, never read from an index
        // that holds a tool hidden from them.
        self::assertNotContains('acme_set_ticket_status', self::names((new ToolSearch($fewer, cache: $cache))->search(new SearchQuery('change', 'ticket status'))));
    }

    public function testABrokenCacheEntryIsBuiltAgain(): void
    {
        self::assertNull(ToolIndex::fromArray(['format' => 0]));
        self::assertNull(ToolIndex::fromArray([]));
        // The shape saved before the index was inverted: rebuilt, never misread.
        self::assertNull(ToolIndex::fromArray(['format' => 1, 'documents' => [], 'weights' => [], 'averages' => [], 'words' => [], 'grams' => [], 'trigramWeight' => 0.6]));
    }

    public function testARestoredIndexScoresExactlyLikeTheBuiltOne(): void
    {
        $documents = [];
        foreach (RecordingGate::fixtureTools() as $name => $tool) {
            $documents[$name] = ['name' => $name, 'title' => $tool->title(), 'description' => $tool->description()];
        }
        $built = ToolIndex::build($documents, ['name' => 1.0, 'title' => 2.0, 'description' => 1.0]);
        $restored = ToolIndex::fromArray($built->toArray());

        self::assertNotNull($restored);
        self::assertSame($built->ids(), $restored->ids());
        foreach (['change the ticket status', 'roll back a deployment', 'statuses', 'nothing like it'] as $query) {
            self::assertSame($built->score($query), $restored->score($query));
        }
    }

    public function testLearningLiftsTheToolThatWorked(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        $query = new SearchQuery('show', 'ticket');
        $before = self::names((new ToolSearch($tools, learning: $store))->search($query));
        $chosen = $before[1];
        for ($run = 0; $run < 5; ++$run) {
            $search = new ToolSearch($tools, learning: $store);
            $search->learn((string) $search->find($query)->searchId, $chosen);
        }

        self::assertSame($chosen, self::names((new ToolSearch($tools, learning: $store))->search($query))[0]);
        self::assertSame($before, self::names((new ToolSearch($tools))->search($query)), 'nothing changes without a store');
    }

    public function testLearningKeepsOnlyTheWordsOfTheTools(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        $search = new ToolSearch($tools, learning: $store);
        $id = $search->find(new SearchQuery('show', 'ticket of Jane Doe'))->searchId;

        $search->learn((string) $id, 'acme_get_ticket');
        $search->learn((string) $id, 'acme_not_a_tool');
        $search->learn(rtrim(strtr(base64_encode('ticket zzsecret'), '+/', '-_'), '='), 'acme_get_ticket');

        $words = array_keys($store->load());
        self::assertContains('ticket', $words);
        self::assertNotContains('jane', $words);
        self::assertNotContains('zzsecret', $words);
        foreach ($store->load() as $byTool) {
            self::assertSame(['acme_get_ticket'], array_keys($byTool));
        }
    }

    public function testLearningNeverBringsAToolTheQueryDoesNotDescribe(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        $store->record(['backup', 'list'], 'acme_delete_file');

        self::assertNotContains('acme_delete_file', self::names((new ToolSearch($tools, learning: $store))->search(new SearchQuery('list', 'backups'))));
    }

    public function testAWordSharedWithALearnedSearchLiftsNothing(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        for ($run = 0; $run < 5; ++$run) {
            $search = new ToolSearch($tools, learning: $store);
            $search->learn((string) $search->find(SearchQuery::text('show ticket'))->searchId, 'acme_get_ticket');
        }
        $assign = SearchQuery::text('assign the ticket');

        // "ticket" alone says which object, not which action: what was
        // learned for showing a ticket must not reorder the tools that
        // assign one.
        self::assertSame(self::names((new ToolSearch($tools))->search($assign)), self::names((new ToolSearch($tools, learning: $store))->search($assign)));
        self::assertSame('acme_get_ticket', self::names((new ToolSearch($tools, learning: $store))->search(SearchQuery::text('show ticket')))[0]);
    }

    public function testTheFileStoreKeepsWhatWasLearned(): void
    {
        $file = self::temporary('learning') . '.json';
        (new FileLearningStore($file))->record(['ticket', 'ticket'], 'acme_get_ticket');
        (new FileLearningStore($file))->record(['ticket'], 'acme_get_ticket');

        self::assertSame(['ticket' => ['acme_get_ticket' => 2]], (new FileLearningStore($file))->load());
        unlink($file);
    }

    public function testAStoreKeepsItsMostUsedCountsWithinItsLimit(): void
    {
        $file = self::temporary('learning') . '.json';
        $words = array_map(static fn(int $i): string => 'word' . $i, range(1, Counts::LIMIT + 500));
        // Run twice, so they outlast the words run once.
        $often = ['often', 'often pair'];
        foreach ([new MemoryLearningStore(), new FileLearningStore($file)] as $store) {
            $store->record($often, 'acme_get_ticket');
            $store->record($often, 'acme_get_ticket');
            // In chunks, as runs of many searches would add them.
            foreach (array_chunk($words, 136) as $chunk) {
                $store->record($chunk, 'acme_get_ticket');
            }
            $store->record(['latest'], 'acme_get_ticket');

            $counts = $store instanceof FileLearningStore ? (new FileLearningStore($file))->load() : $store->load();
            self::assertLessThanOrEqual(Counts::LIMIT, array_sum(array_map('count', $counts)));
            self::assertSame(2, $counts['often']['acme_get_ticket'] ?? null);
            self::assertSame(2, $counts['often pair']['acme_get_ticket'] ?? null);
            self::assertSame(1, $counts['latest']['acme_get_ticket'] ?? null);
        }
        unlink($file);
    }

    public function testAToolWithANumericNameIsFoundAndLearned(): void
    {
        $tools = ToolSet::of([
            ['name' => '123', 'description' => 'Export the backup archive', 'inputSchema' => ['type' => 'object']],
            ['name' => '42', 'description' => 'Remove a backup archive', 'inputSchema' => ['type' => 'object']],
            ['name' => 'list_files', 'description' => 'List the files', 'inputSchema' => ['type' => 'object']],
        ]);
        $learning = new MemoryLearningStore();
        $search = new ToolSearch($tools, new SearchOptions(), new MemoryIndexCache(), $learning);

        $result = $search->find(SearchQuery::text('export backup archive'));
        self::assertSame('123', self::names($result->tools)[0]);
        self::assertNotNull($result->searchId);

        $search->learn($result->searchId, '42');
        self::assertSame(['42', '123'], self::names($search->search('backup archive')), 'what was learned lifts a numeric name too');
    }

    public function testFiveHundredToolsAreSearchedFast(): void
    {
        $definitions = [];
        foreach (RecordingGate::fixtureTools() as $tool) {
            for ($copy = 0; $copy < 10; ++$copy) {
                $definitions[] = ['name' => $tool->name . '_' . $copy] + $tool->toArray();
            }
        }
        $tools = ToolSet::of($definitions);
        $query = new SearchQuery('change', 'ticket status', 'move a ticket to done');
        $cache = new MemoryIndexCache();
        $start = hrtime(true);
        (new ToolSearch($tools, cache: $cache))->search($query);
        $built = (hrtime(true) - $start) / 1e6;
        $start = hrtime(true);
        (new ToolSearch($tools, cache: $cache))->search($query);
        $cached = (hrtime(true) - $start) / 1e6;

        // Generous on purpose: CI machines are slow and shared.
        self::assertLessThan(500, $built);
        self::assertLessThan($built, $cached, 'the cache saves the build');
    }
}
