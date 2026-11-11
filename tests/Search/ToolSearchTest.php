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

        self::assertSame(['set', 'order', 'status'], $analyzer->words('set_order_status'));
        self::assertSame(['set', 'order', 'status'], $analyzer->words('setOrderStatus'));
        self::assertSame(['get', 'html', 'page'], $analyzer->words('getHTMLPage'));
        self::assertSame(['refund', 'order'], $analyzer->words('Refund the order 1042'), 'stop words and numbers left out');
        self::assertSame(['categorie', 'etat'], $analyzer->words('Catégorie État'), 'accents folded without mbstring');
    }

    public function testTheMostRelevantToolComesFirst(): void
    {
        $search = new ToolSearch(RecordingGate::storeTools());

        self::assertSame('acme_set_order_status', $search->search('change the status of an order')[0]->name);
        self::assertSame('acme_refund_payment', $search->search('refund a payment')[0]->name);
        self::assertSame('acme_list_low_inventory', $search->search('low inventory')[0]->name);
    }

    public function testFarWeakerMatchesAreCut(): void
    {
        $found = (new ToolSearch(RecordingGate::storeTools()))->search('coupons');

        self::assertLessThanOrEqual(5, count($found), 'not every tool that mentions a discount');
        self::assertContains('acme_list_coupons', self::names($found));
    }

    public function testTheLimitHolds(): void
    {
        $search = new ToolSearch(RecordingGate::storeTools(), new SearchOptions(cut: 0.0, limit: 3));

        self::assertCount(3, $search->search('order'));
        self::assertCount(1, $search->search('order', 1));
    }

    public function testNothingMatchesNothing(): void
    {
        self::assertSame([], (new ToolSearch(RecordingGate::storeTools()))->search('zzzz qqqq'));
        self::assertSame([], (new ToolSearch(RecordingGate::storeTools()))->search('the of a'));
    }

    public function testTheSameQueryAlwaysGivesTheSameOrder(): void
    {
        $search = new ToolSearch(RecordingGate::storeTools(), new SearchOptions(cut: 0.0));

        self::assertSame(self::names($search->search('delete')), self::names($search->search('delete')));
    }

    public function testHostSynonymsBringTheHostVocabulary(): void
    {
        $tools = RecordingGate::storeTools();

        self::assertNotSame('acme_get_shipment_tracking', (new ToolSearch($tools))->search('where is my parcel')[0]->name ?? null);
        self::assertSame(
            'acme_get_shipment_tracking',
            (new ToolSearch($tools, new SearchOptions(synonyms: ['parcel' => 'shipment tracking'])))->search('where is my parcel')[0]->name,
        );
    }

    public function testASharedVerbIsNotTakenForAPrefix(): void
    {
        $tools = ToolSet::of([
            ['name' => 'get_order', 'description' => 'One order.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'get_customer', 'description' => 'One customer.', 'inputSchema' => ['type' => 'object']],
        ]);

        self::assertSame('get_customer', (new ToolSearch($tools))->search('get customer')[0]->name);
    }

    public function testFiveHundredToolsAreSearchedFast(): void
    {
        $definitions = [];
        foreach (RecordingGate::storeTools() as $tool) {
            for ($copy = 0; $copy < 10; ++$copy) {
                $definitions[] = ['name' => $tool->name . '_' . $copy] + $tool->toArray();
            }
        }
        $start = hrtime(true);
        (new ToolSearch(ToolSet::of($definitions)))->search('change the status of an order');
        $milliseconds = (hrtime(true) - $start) / 1e6;

        // Generous on purpose: CI machines are slow and shared. On a laptop
        // this is about 25 ms for the index and the search together.
        self::assertLessThan(500, $milliseconds);
    }
}
