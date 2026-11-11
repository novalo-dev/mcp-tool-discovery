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
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use PHPUnit\Framework\TestCase;

final class CompactModeTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the structured answer
     */
    private static function search(array $arguments, ?CompactMode $mode = null, ?RecordingGate $gate = null): array
    {
        $result = (new Discovery($mode ?? new CompactMode(), $gate ?? new RecordingGate()))
            ->call(CompactMode::SEARCH, $arguments, RecordingGate::fixtureTools());
        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function runTool(string $runner, array $arguments, ?RecordingGate $gate = null): CallResult
    {
        return (new Discovery(new CompactMode(), $gate ?? new RecordingGate()))->call($runner, $arguments, RecordingGate::fixtureTools());
    }

    private static function text(CallResult $result): string
    {
        return implode("\n", array_map(static fn(array $block): string => is_string($block['text'] ?? null) ? $block['text'] : '', $result->content));
    }

    public function testTheListHoldsThePinnedToolsAndTheThree(): void
    {
        $mode = new CompactMode(prefix: 'acme_', pinned: ['acme_get_settings']);
        $names = array_map(static fn(Tool $tool): string => $tool->name, $mode->listTools(RecordingGate::fixtureTools()));

        self::assertSame(['acme_get_settings', 'acme_search_tools', 'acme_run_read_tool', 'acme_run_write_tool'], $names);
    }

    public function testAPinnedToolTheCallerMayNotSeeIsNotListed(): void
    {
        $mode = new CompactMode(pinned: ['acme_get_settings']);
        $listed = (new Discovery($mode, new RecordingGate(['acme_get_settings'])))->listTools(RecordingGate::fixtureTools(), Revision::latest());

        self::assertNotContains('acme_get_settings', array_column($listed['tools'], 'name'));
    }

    public function testOnlyTheChangeRunnerIsMarkedDestructive(): void
    {
        $tools = [];
        foreach ((new CompactMode())->listTools(RecordingGate::fixtureTools()) as $tool) {
            $tools[$tool->name] = $tool;
        }

        self::assertTrue($tools[CompactMode::SEARCH]->readsOnly());
        self::assertTrue($tools[CompactMode::RUN_READ]->readsOnly());
        self::assertFalse($tools[CompactMode::RUN_WRITE]->readsOnly());
        self::assertTrue($tools[CompactMode::RUN_WRITE]->annotations()['destructiveHint']);
    }

    public function testASearchGivesSchemasAndTheRunnerToUse(): void
    {
        $answer = self::search(['query' => 'change the status of a ticket']);

        self::assertIsArray($answer['tools']);
        self::assertIsArray($answer['tools'][0]);
        self::assertSame('acme_set_ticket_status', $answer['tools'][0]['name']);
        self::assertSame(CompactMode::RUN_WRITE, $answer['tools'][0]['run_with']);
        self::assertSame('object', $answer['tools'][0]['inputSchema']['type'] ?? null);
    }

    public function testNamesAreDescribedFirstAndUnknownOnesSaidSo(): void
    {
        $answer = self::search(['names' => ['acme_get_file', 'acme_nope']]);

        self::assertIsArray($answer['tools']);
        self::assertSame('acme_get_file', $answer['tools'][0]['name'] ?? null);
        self::assertSame(['acme_nope'], $answer['unknown']);
    }

    public function testAHiddenNameIsAnsweredLikeAMissingOne(): void
    {
        $gate = new RecordingGate(['acme_list_users']);

        self::assertSame(
            self::search(['names' => ['acme_nope']], null, $gate)['unknown'],
            ['acme_nope'],
        );
        self::assertSame(
            self::search(['names' => ['acme_list_users']], null, $gate)['unknown'],
            ['acme_list_users'],
        );
    }

    public function testNoArgumentListsEveryToolGroupedWhenTheHostSaysHow(): void
    {
        $flat = self::search([]);
        self::assertIsArray($flat['tools']);
        self::assertCount(count(RecordingGate::fixtureTools()), $flat['tools']);

        $grouped = self::search([], new CompactMode(groupOf: static fn(Tool $tool): string => explode('_', $tool->name)[2] ?? 'other'));
        self::assertIsArray($grouped['tools']);
        self::assertIsArray($grouped['tools'][0]);
        self::assertArrayHasKey('group', $grouped['tools'][0]);
    }

    public function testNothingFoundGivesAHint(): void
    {
        $answer = self::search(['query' => 'zzzz']);

        self::assertSame([], $answer['tools']);
        self::assertArrayHasKey('hint', $answer);
    }

    public function testAChangeSentToTheReadRunnerIsRefusedAndNeverRuns(): void
    {
        $gate = new RecordingGate();
        $result = self::runTool(CompactMode::RUN_READ, ['name' => 'acme_delete_file'], $gate);

        self::assertTrue($result->isError);
        self::assertStringContainsString(CompactMode::RUN_WRITE, self::text($result));
        self::assertSame([], $gate->called);
    }

    public function testAReadSentToTheChangeRunnerIsRefused(): void
    {
        $result = self::runTool(CompactMode::RUN_WRITE, ['name' => 'acme_get_file']);

        self::assertTrue($result->isError);
        self::assertStringContainsString(CompactMode::RUN_READ, self::text($result));
    }

    public function testARunnerDoesNotRunARunnerOrTheSearch(): void
    {
        foreach ([CompactMode::RUN_READ, CompactMode::RUN_WRITE, CompactMode::SEARCH] as $inner) {
            self::assertTrue(self::runTool(CompactMode::RUN_READ, ['name' => $inner])->isError, $inner);
        }
    }

    public function testTheArgumentsReachTheToolUnchanged(): void
    {
        $gate = new RecordingGate();
        self::runTool(CompactMode::RUN_WRITE, ['name' => 'acme_set_ticket_status', 'arguments' => ['ticket_id' => '7', 'status' => 'solved']], $gate);

        self::assertSame([['name' => 'acme_set_ticket_status', 'arguments' => ['ticket_id' => '7', 'status' => 'solved']]], $gate->called);
    }

    public function testAFailedRunCarriesTheToolsParameters(): void
    {
        $result = self::runTool(CompactMode::RUN_WRITE, ['name' => 'acme_deactivate_user'], new RecordingGate([], ['acme_deactivate_user']));

        self::assertTrue($result->isError);
        self::assertStringContainsString('Parameters of acme_deactivate_user', self::text($result));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedRuns(): iterable
    {
        yield 'no name' => [[]];
        yield 'a name that is not a string' => [['name' => 1]];
        yield 'arguments that are not an object' => [['name' => 'acme_get_file', 'arguments' => 'x']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRuns')]
    public function testAMalformedRunIsRefused(array $arguments): void
    {
        self::assertTrue(self::runTool(CompactMode::RUN_READ, $arguments)->isError);
    }

    public function testTheGuideHeaderSaysWhichRunnerToUse(): void
    {
        $mode = new CompactMode();
        $header = $mode->guideHeader(['acme_get_file', 'acme_set_ticket_status', 'acme_list_users'], RecordingGate::fixtureTools()->filter(static fn(Tool $tool): bool => $tool->name !== 'acme_list_users'));

        self::assertStringContainsString('run_read_tool: acme_get_file', $header);
        self::assertStringContainsString('run_write_tool: acme_set_ticket_status', $header);
        self::assertStringNotContainsString('acme_list_users', $header);
        self::assertSame('', $mode->guideHeader(['acme_nope'], RecordingGate::fixtureTools()));
    }
}
