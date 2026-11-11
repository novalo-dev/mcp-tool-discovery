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
use Novalo\McpToolDiscovery\Search\SearchOptions;
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

    public function testTheAnalyzerSplitsEveryNamingStyle(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['set', 'ticket', 'status'], $analyzer->words('set_ticket_status'));
        self::assertSame(['set', 'ticket', 'status'], $analyzer->words('setTicketStatus'));
        self::assertSame(['get', 'html', 'page'], $analyzer->words('getHTMLPage'));
        self::assertSame(['close', 'ticket'], $analyzer->words('Close the ticket 1042'), 'stop words and numbers left out');
        self::assertSame(['categorie', 'etat'], $analyzer->words('Catégorie État'), 'accents folded without mbstring');
    }

    public function testTheMostRelevantToolComesFirst(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools());

        self::assertSame('acme_set_ticket_status', $search->search('change the status of a ticket')[0]->name);
        self::assertSame('acme_rollback_deployment', $search->search('roll back a deployment')[0]->name);
        self::assertSame('acme_list_backups', $search->search('list backups')[0]->name);
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
        self::assertSame([], (new ToolSearch(RecordingGate::fixtureTools()))->search('zzzz qqqq'));
        self::assertSame([], (new ToolSearch(RecordingGate::fixtureTools()))->search('the of a'));
    }

    public function testTheSameQueryAlwaysGivesTheSameOrder(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools(), new SearchOptions(cut: 0.0));

        self::assertSame(self::names($search->search('delete')), self::names($search->search('delete')));
    }

    public function testHostSynonymsBringTheHostVocabulary(): void
    {
        $tools = RecordingGate::fixtureTools();

        self::assertNotSame('acme_create_event', (new ToolSearch($tools))->search('create a meeting')[0]->name ?? null);
        self::assertSame(
            'acme_create_event',
            (new ToolSearch($tools, new SearchOptions(synonyms: ['meeting' => 'calendar event'])))->search('create a meeting')[0]->name,
        );
    }

    public function testASharedVerbIsNotTakenForAPrefix(): void
    {
        $tools = ToolSet::of([
            ['name' => 'get_file', 'description' => 'One file.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'get_user', 'description' => 'One user.', 'inputSchema' => ['type' => 'object']],
        ]);

        self::assertSame('get_user', (new ToolSearch($tools))->search('get user')[0]->name);
    }

    public function testFiveHundredToolsAreSearchedFast(): void
    {
        $definitions = [];
        foreach (RecordingGate::fixtureTools() as $tool) {
            for ($copy = 0; $copy < 10; ++$copy) {
                $definitions[] = ['name' => $tool->name . '_' . $copy] + $tool->toArray();
            }
        }
        $start = hrtime(true);
        (new ToolSearch(ToolSet::of($definitions)))->search('change the status of a ticket');
        $milliseconds = (hrtime(true) - $start) / 1e6;

        // Generous on purpose: CI machines are slow and shared. On a laptop
        // this is about 25 ms for the index and the search together.
        self::assertLessThan(500, $milliseconds);
    }
}
