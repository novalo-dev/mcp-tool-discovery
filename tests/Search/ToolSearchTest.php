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
use Novalo\McpToolDiscovery\Search\Index;
use Novalo\McpToolDiscovery\Search\Learning\FileLearningStore;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\SearchQuery;
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
        self::assertNull(Index::fromArray(['format' => 0]));
        self::assertNull(Index::fromArray([]));
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

    public function testTheFileStoreKeepsWhatWasLearned(): void
    {
        $file = self::temporary('learning') . '.json';
        (new FileLearningStore($file))->record(['ticket', 'ticket'], 'acme_get_ticket');
        (new FileLearningStore($file))->record(['ticket'], 'acme_get_ticket');

        self::assertSame(['ticket' => ['acme_get_ticket' => 2]], (new FileLearningStore($file))->load());
        unlink($file);
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
