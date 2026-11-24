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
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tests\Fixtures\ArgumentGate;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use PHPUnit\Framework\TestCase;

final class CompactBatchTest extends TestCase
{
    private const TOOL = 'acme_set_ticket_status';

    /**
     * @param array<string, mixed> $arguments
     */
    private static function batch(array $arguments, ?ToolGate $gate = null, int $limit = 5, SearchSetup $setup = new SearchSetup()): CallResult
    {
        return (new Discovery(new CompactMode(batchLimit: $limit), $gate ?? new RecordingGate(), $setup))
            ->call(CompactMode::RUN_WRITE_BATCH, $arguments, RecordingGate::fixtureTools());
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(CallResult $result): array
    {
        self::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function tickets(string ...$ids): array
    {
        return array_map(static fn(string $id): array => ['ticket_id' => $id, 'status' => 'solved'], array_values($ids));
    }

    /**
     * @param list<string> $refusedIds
     */
    private static function gate(array $refusedIds = []): ArgumentGate
    {
        return new ArgumentGate('ticket_id', $refusedIds);
    }

    public function testTheBatchRunnerIsOffByDefault(): void
    {
        $mode = new CompactMode();
        $names = array_map(static fn(Tool $tool): string => $tool->name, $mode->listTools(RecordingGate::fixtureTools()));

        self::assertNotContains(CompactMode::RUN_WRITE_BATCH, $names);
        self::assertFalse($mode->owns(CompactMode::RUN_WRITE_BATCH), 'a host tool of that name stays the host\'s');
        $gate = new RecordingGate();
        $result = (new Discovery($mode, $gate))->call(CompactMode::RUN_WRITE_BATCH, ['name' => self::TOOL, 'items' => self::tickets('1')], RecordingGate::fixtureTools());
        self::assertTrue($result->isError);
        self::assertSame([], $gate->called);
    }

    public function testTheBatchRunnerIsListedAsADestructiveChangeWithItsLimit(): void
    {
        $tools = [];
        foreach ((new CompactMode(prefix: 'acme_', batchLimit: 30))->listTools(RecordingGate::fixtureTools()) as $tool) {
            $tools[$tool->name] = $tool;
        }
        $batch = $tools['acme_' . CompactMode::RUN_WRITE_BATCH] ?? null;

        self::assertNotNull($batch);
        self::assertFalse($batch->readsOnly());
        self::assertTrue($batch->annotations()['destructiveHint']);
        self::assertStringContainsString('complete list', $batch->description());
        self::assertSame(30, $batch->inputSchema()['properties']['items']['maxItems'] ?? null);
    }

    public function testEachItemMeetsTheGateAndRunsInOrderWithItsArguments(): void
    {
        $gate = self::gate();
        $answer = self::answer(self::batch(['name' => self::TOOL, 'items' => self::tickets('1', '2', '3')], $gate));

        self::assertSame(['1', '2', '3'], $gate->authorized);
        self::assertSame(self::tickets('1', '2', '3'), $gate->called);
        self::assertSame(3, $answer['succeeded']);
        self::assertSame(0, $answer['failed']);
    }

    public function testARefusedItemIsReportedAndNeverStopsTheOthers(): void
    {
        $gate = self::gate(['2']);
        $result = self::batch(['name' => self::TOOL, 'items' => self::tickets('1', '2', '3')], $gate);
        $answer = self::answer($result);

        self::assertFalse($result->isError, 'two items changed something');
        self::assertSame(self::tickets('1', '3'), $gate->called);
        self::assertSame(2, $answer['succeeded']);
        self::assertSame(1, $answer['failed']);
        self::assertIsArray($answer['results']);
        self::assertSame([false, true, false], array_column($answer['results'], 'isError'));
        self::assertSame([0, 1, 2], array_column($answer['results'], 'item'));
        self::assertStringContainsString('ticket_id 2', (string) json_encode($answer['results'][1]));
    }

    public function testABatchWhereEveryItemFailsIsAnError(): void
    {
        $result = self::batch(['name' => self::TOOL, 'items' => self::tickets('1', '2')], self::gate(['1', '2']));

        self::assertTrue($result->isError);
        self::assertSame(2, self::answer($result)['failed']);
    }

    public function testAHiddenToolIsAnsweredLikeAMissingOneAndTheGateIsNeverAsked(): void
    {
        $gate = new RecordingGate([self::TOOL]);
        $hidden = self::batch(['name' => self::TOOL, 'items' => self::tickets('1')], $gate);
        $missing = self::batch(['name' => 'acme_nope', 'items' => self::tickets('1')]);

        self::assertTrue($hidden->isError);
        self::assertSame(str_replace('acme_nope', self::TOOL, json_encode($missing->content) ?: ''), json_encode($hidden->content));
        self::assertSame([], $gate->authorized);
        self::assertSame([], $gate->called);
    }

    public function testAReadARunnerOrTheSearchIsRefused(): void
    {
        foreach (['acme_get_file', CompactMode::RUN_WRITE, CompactMode::RUN_WRITE_BATCH, CompactMode::SEARCH] as $name) {
            $gate = new RecordingGate();
            self::assertTrue(self::batch(['name' => $name, 'items' => [[]]], $gate)->isError, $name);
            self::assertSame([], $gate->called, $name);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedBatches(): iterable
    {
        yield 'no name' => [['items' => [[]]]];
        yield 'no items' => [['name' => self::TOOL]];
        yield 'an empty list' => [['name' => self::TOOL, 'items' => []]];
        yield 'items that are an object' => [['name' => self::TOOL, 'items' => ['a' => ['ticket_id' => '1']]]];
        yield 'an item that is not an object' => [['name' => self::TOOL, 'items' => [['ticket_id' => '1'], 'x']]];
        yield 'an item that is a list' => [['name' => self::TOOL, 'items' => [['ticket_id' => '1'], ['1', '2']]]];
        yield 'more items than the limit' => [['name' => self::TOOL, 'items' => self::tickets('1', '2', '3', '4', '5', '6')]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBatches')]
    public function testAMalformedBatchRunsNothing(array $arguments): void
    {
        $gate = new RecordingGate();

        self::assertTrue(self::batch($arguments, $gate)->isError);
        self::assertSame([], $gate->authorized);
        self::assertSame([], $gate->called);
    }

    public function testTheSearchLearnsOnceFromTheFirstItemThatSucceeds(): void
    {
        $store = new MemoryLearningStore();
        $setup = new SearchSetup(learning: $store);
        $found = (new Discovery(new CompactMode(batchLimit: 5), new RecordingGate(), $setup))
            ->call(CompactMode::SEARCH, ['action' => 'change', 'object' => 'ticket status'], RecordingGate::fixtureTools());
        self::assertIsArray($found->structuredContent);
        $searchId = $found->structuredContent['search_id'] ?? null;
        self::assertIsString($searchId);

        self::batch(['name' => self::TOOL, 'items' => self::tickets('1', '2', '3'), 'search_id' => $searchId], self::gate(['1']), 5, $setup);

        $counts = $store->load();
        self::assertNotSame([], $counts);
        foreach ($counts as $tools) {
            self::assertSame([self::TOOL => 1], $tools);
        }
    }

    public function testABatchReportsItsItemsWhenAToolAnswersWithBrokenText(): void
    {
        // A host tool that cuts its answer by bytes, in the middle of "é".
        $gate = new class implements ToolGate {
            public int $runs = 0;

            public function visible(Tool $tool): bool
            {
                return true;
            }

            public function authorize(Tool $tool, array $arguments): ?CallResult
            {
                return null;
            }

            public function call(Tool $tool, array $arguments): CallResult
            {
                ++$this->runs;
                $cut = substr('Set to résolu', 0, 9);

                return new CallResult([['type' => 'text', 'text' => $cut]], false, ['status' => $cut]);
            }
        };

        $result = self::batch(['name' => self::TOOL, 'items' => self::tickets('1', '2')], $gate);
        $answer = self::answer($result);

        self::assertSame(2, $gate->runs);
        self::assertFalse($result->isError);
        self::assertSame(2, $answer['succeeded']);
        self::assertIsString($result->content[0]['text'] ?? null);
        self::assertSame("Set to r\u{FFFD}", json_decode($result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR)['results'][1]['structuredContent']['status']);
        self::assertSame(1, preg_match('//u', json_encode($answer, JSON_THROW_ON_ERROR)));
    }

    public function testAFailedItemAddsTheToolsParametersOnce(): void
    {
        $result = self::batch(['name' => self::TOOL, 'items' => self::tickets('1', '2')], self::gate(['1', '2']));
        $texts = array_column($result->content, 'text');

        self::assertCount(1, array_filter($texts, static fn($text): bool => is_string($text) && str_starts_with($text, 'Parameters of ' . self::TOOL)));
    }

    public function testTheGuideHeaderNamesTheBatchRunnerOnlyWhenItIsOn(): void
    {
        $tools = RecordingGate::fixtureTools();

        self::assertStringContainsString(CompactMode::RUN_WRITE_BATCH, (new CompactMode(batchLimit: 5))->guideHeader([self::TOOL], $tools));
        self::assertStringNotContainsString(CompactMode::RUN_WRITE_BATCH, (new CompactMode())->guideHeader([self::TOOL], $tools));
    }

    public function testANegativeLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CompactMode(batchLimit: -1);
    }

    public function testTheInstructionsNameTheBatchRunnerOnlyWhenItIsOn(): void
    {
        self::assertStringContainsString(CompactMode::RUN_WRITE_BATCH, (new CompactMode(batchLimit: 5))->instructions());
        self::assertStringNotContainsString(CompactMode::RUN_WRITE_BATCH, (new CompactMode())->instructions());
    }
}
