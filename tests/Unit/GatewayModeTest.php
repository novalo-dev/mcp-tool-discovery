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

namespace Novalo\McpToolDiscovery\Tests\Unit;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\TestCase;

final class GatewayModeTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     */
    private static function call(array $arguments, ?GatewayMode $mode = null, ?RecordingGate $gate = null, SearchSetup $setup = new SearchSetup()): CallResult
    {
        return (new Discovery($mode ?? new GatewayMode(), $gate ?? new RecordingGate(['acme_list_users'], ['acme_deactivate_user']), $setup))
            ->call(GatewayMode::NAME, $arguments, RecordingGate::fixtureTools());
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(CallResult $result): array
    {
        self::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }

    private static function text(CallResult $result): string
    {
        return implode("\n", array_map(static fn(array $block): string => is_string($block['text'] ?? null) ? $block['text'] : '', $result->content));
    }

    /**
     * @return list<Tool>
     */
    private static function listed(GatewayMode $mode, ?ToolSet $tools = null): array
    {
        return $mode->listTools($tools ?? RecordingGate::fixtureTools());
    }

    public function testOneToolIsListedAndMarkedDestructiveWhenAChangeCanGoThrough(): void
    {
        $listed = self::listed(new GatewayMode(prefix: 'acme_', title: 'Outils'));

        self::assertCount(1, $listed);
        self::assertSame('acme_use_tools', $listed[0]->name);
        self::assertSame('Outils', $listed[0]->title());
        self::assertFalse($listed[0]->readsOnly());
        self::assertTrue($listed[0]->annotations()['destructiveHint'] ?? null);
    }

    public function testTheToolOnlyReadsWhenEveryVisibleToolOnlyReads(): void
    {
        $reads = RecordingGate::fixtureTools()->filter(static fn(Tool $tool): bool => $tool->readsOnly());
        $listed = self::listed(new GatewayMode(), $reads);

        self::assertCount(1, $listed);
        self::assertTrue($listed[0]->readsOnly());
        self::assertFalse($listed[0]->annotations()['destructiveHint'] ?? null);
    }

    public function testWithChangesApartTheChangesAreListedAsThemselves(): void
    {
        $listed = self::listed(new GatewayMode(changesApart: true));
        $names = array_map(static fn(Tool $tool): string => $tool->name, $listed);

        self::assertSame(GatewayMode::NAME, $names[0]);
        self::assertTrue($listed[0]->readsOnly());
        self::assertContains('acme_delete_file', $names);
        self::assertNotContains('acme_get_file', $names);
        foreach (array_slice($listed, 1) as $tool) {
            self::assertFalse($tool->readsOnly(), $tool->name);
        }
    }

    public function testASearchGivesSchemasAndSaysWhatRunsEachTool(): void
    {
        $tools = self::answer(self::call(['operation' => 'search', 'action' => 'change', 'object' => 'ticket status']))['tools'];
        self::assertIsArray($tools);
        self::assertIsArray($tools[0]);
        self::assertSame('acme_set_ticket_status', $tools[0]['name']);
        self::assertSame(GatewayMode::NAME, $tools[0]['run_with']);
        self::assertArrayHasKey('inputSchema', $tools[0]);

        $apart = self::answer(self::call(['operation' => 'search', 'action' => 'change', 'object' => 'ticket status'], new GatewayMode(changesApart: true)))['tools'];
        self::assertIsArray($apart);
        self::assertIsArray($apart[0]);
        self::assertSame('acme_set_ticket_status', $apart[0]['run_with']);
    }

    public function testEveryToolThatChangesSomethingIsMarkedSoTheModelKnowsWhatToConfirm(): void
    {
        $tools = RecordingGate::fixtureTools();
        $found = self::answer(self::call(['operation' => 'search', 'action' => 'change', 'object' => 'ticket status']))['tools'];
        $every = self::answer(self::call(['operation' => 'search']))['tools'];
        self::assertIsArray($found);
        self::assertIsArray($every);
        $marked = 0;
        foreach ([...$found, ...$every] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['name']);
            $tool = $tools->get($entry['name']);
            self::assertNotNull($tool);
            // Only changes carry the mark: a read pays nothing for it.
            self::assertSame($tool->readsOnly() ? null : true, $entry['changes'] ?? null, $entry['name']);
            $marked += isset($entry['changes']) ? 1 : 0;
        }
        self::assertGreaterThan(0, $marked);
        self::assertStringContainsString('a tool marked changes.', self::listed(new GatewayMode())[0]->description());

        // Where run_with already tells the change apart, the mark would only
        // repeat it.
        $apart = self::answer(self::call(['operation' => 'search', 'action' => 'change', 'object' => 'ticket status'], new GatewayMode(changesApart: true)))['tools'];
        self::assertIsArray($apart);
        self::assertSame([], array_filter($apart, static fn($entry): bool => is_array($entry) && isset($entry['changes'])));
    }

    public function testTheSearchAnswersLikeTheCompactSearch(): void
    {
        $arguments = ['action' => 'list', 'object' => 'backups', 'description' => 'every backup of last week'];
        $gateway = self::answer(self::call(['operation' => 'search'] + $arguments));
        $compact = (new Discovery(new CompactMode(), new RecordingGate(['acme_list_users'], ['acme_deactivate_user'])))
            ->call(CompactMode::SEARCH, $arguments, RecordingGate::fixtureTools())->structuredContent;
        self::assertIsArray($compact);
        self::assertIsArray($gateway['tools']);
        self::assertIsArray($compact['tools']);

        self::assertSame(array_column($compact['tools'], 'name'), array_column($gateway['tools'], 'name'));
    }

    public function testASearchWithNoFieldListsEveryVisibleTool(): void
    {
        $tools = self::answer(self::call(['operation' => 'search']))['tools'];
        self::assertIsArray($tools);

        self::assertCount(49, $tools);
        self::assertNotContains('acme_list_users', array_column($tools, 'name'));
    }

    public function testStrayNamesDoNotTurnASearchIntoADescription(): void
    {
        $tools = self::answer(self::call(['operation' => 'search', 'names' => ['acme_get_file']]))['tools'];
        self::assertIsArray($tools);

        self::assertCount(49, $tools, 'names belongs to describe: a search with no field lists everything');
    }

    public function testDescribeGivesTheNamedToolsAndAHiddenOneReadsAsMissing(): void
    {
        $answer = self::answer(self::call(['operation' => 'describe', 'names' => ['acme_get_file', 'acme_list_users', 'acme_nope']]));
        self::assertIsArray($answer['tools']);

        self::assertSame(['acme_get_file'], array_column($answer['tools'], 'name'));
        self::assertSame(['acme_list_users', 'acme_nope'], $answer['unknown']);
        self::assertTrue(self::call(['operation' => 'describe'])->isError);
    }

    public function testARunGoesThroughTheGateAndPassesTheSearchIdOn(): void
    {
        $store = new MemoryLearningStore();
        $setup = new SearchSetup(learning: $store);
        $answer = self::answer(self::call(['operation' => 'search', 'action' => 'show', 'object' => 'ticket'], null, null, $setup));
        self::assertIsString($answer['search_id']);

        $gate = new RecordingGate();
        $result = self::call(['operation' => 'run', 'name' => 'acme_get_ticket', 'arguments' => ['ticket_id' => '7'], 'search_id' => $answer['search_id']], null, $gate, $setup);

        self::assertFalse($result->isError);
        self::assertSame([['name' => 'acme_get_ticket', 'arguments' => ['ticket_id' => '7']]], $gate->called);
        self::assertArrayHasKey('ticket', $store->load());
    }

    public function testAHiddenToolReadsLikeOneThatDoesNotExist(): void
    {
        $gate = new RecordingGate(['acme_list_users']);
        $hidden = self::call(['operation' => 'run', 'name' => 'acme_list_users'], null, $gate);
        $missing = self::call(['operation' => 'run', 'name' => 'acme_nope'], null, $gate);

        self::assertSame(str_replace('acme_list_users', 'X', self::text($hidden)), str_replace('acme_nope', 'X', self::text($missing)));
        self::assertSame([], $gate->authorized);
    }

    public function testARefusedRunNeverRunsAndGetsItsParameters(): void
    {
        $gate = new RecordingGate([], ['acme_deactivate_user']);
        $result = self::call(['operation' => 'run', 'name' => 'acme_deactivate_user'], null, $gate);

        self::assertTrue($result->isError);
        self::assertSame([], $gate->called);
        self::assertStringContainsString('Parameters of acme_deactivate_user', self::text($result));
    }

    public function testWithChangesApartAChangeIsRefusedThroughTheGatewayBeforeTheGate(): void
    {
        $gate = new RecordingGate();
        $result = self::call(['operation' => 'run', 'name' => 'acme_delete_file'], new GatewayMode(changesApart: true), $gate);

        self::assertTrue($result->isError);
        self::assertStringContainsString('call it by its own name', self::text($result));
        self::assertSame([], $gate->authorized);
    }

    public function testMalformedCallsAreAnsweredAsErrors(): void
    {
        foreach ([[], ['operation' => 'delete'], ['operation' => 'run'], ['operation' => 'run', 'name' => 'acme_get_file', 'arguments' => 'x'], ['operation' => 'run', 'name' => GatewayMode::NAME], ['operation' => 'search', 'query' => 3]] as $arguments) {
            self::assertTrue(self::call($arguments)->isError, (string) json_encode($arguments));
        }
    }

    public function testALimitBelowOneIsRefusedAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GatewayMode(maxDescribed: 0);
    }

    public function testAPrefixThatMakesNoValidNameIsRefusedAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('acme tools use_tools');
        new GatewayMode(prefix: 'acme tools ');
    }

    public function testAPrefixUpToTheLongestNameIsTaken(): void
    {
        self::assertTrue((new GatewayMode(prefix: str_repeat('a', 128 - strlen(GatewayMode::NAME))))->owns(str_repeat('a', 128 - strlen(GatewayMode::NAME)) . GatewayMode::NAME));
    }

    public function testTheGuideHeaderSendsEveryToolThroughTheGateway(): void
    {
        $header = (new GatewayMode(prefix: 'acme_'))->guideHeader(['acme_get_file', 'acme_set_ticket_status', 'acme_list_users', 'acme_use_tools'], RecordingGate::fixtureTools()->filter(static fn(Tool $tool): bool => $tool->name !== 'acme_list_users'));

        self::assertStringContainsString("- through acme_use_tools: acme_get_file, acme_set_ticket_status\n", $header);
        self::assertStringContainsString('operation describe (names)', $header);
        self::assertStringNotContainsString('acme_list_users', $header);
        self::assertStringNotContainsString('directly', $header);
        self::assertSame('', (new GatewayMode())->guideHeader(['acme_nope'], RecordingGate::fixtureTools()));
    }

    public function testWithChangesApartTheGuideHeaderSaysAChangeIsCalledDirectly(): void
    {
        $mode = new GatewayMode(changesApart: true);
        $header = $mode->guideHeader(['acme_get_file', 'acme_set_ticket_status'], RecordingGate::fixtureTools());

        self::assertStringContainsString("- through use_tools: acme_get_file\n", $header);
        self::assertStringContainsString("- directly, as they are in your list: acme_set_ticket_status\n", $header);
        // Changes alone are all in the list: nothing to say.
        self::assertSame('', $mode->guideHeader(['acme_set_ticket_status'], RecordingGate::fixtureTools()));
    }

    public function testTheInstructionsSendEveryToolThroughTheGateway(): void
    {
        $instructions = (new GatewayMode(prefix: 'acme_'))->instructions();

        self::assertStringContainsString('Every tool of this server goes through acme_use_tools', $instructions);
        self::assertStringContainsString('operation describe', $instructions);
        self::assertStringNotContainsString('directly', $instructions);
    }

    public function testWithChangesApartTheInstructionsSayAChangeIsCalledDirectly(): void
    {
        $instructions = (new GatewayMode(changesApart: true))->instructions();

        self::assertStringContainsString('only read go through use_tools', $instructions);
        self::assertStringContainsString('changes something is in your tool list: call it directly', $instructions);
    }
}
