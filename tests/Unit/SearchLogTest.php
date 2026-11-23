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
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchLog;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tests\Fixtures\FailingGate;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingLog;
use Novalo\McpToolDiscovery\Tool\Tool;
use PHPUnit\Framework\TestCase;

final class SearchLogTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     */
    private static function call(string $name, array $arguments, SearchLog $log, ?ToolGate $gate = null, ?MemoryLearningStore $store = null): CallResult
    {
        return (new Discovery(new CompactMode(), $gate ?? new RecordingGate(), new SearchSetup(learning: $store, log: $log)))
            ->call($name, $arguments, RecordingGate::fixtureTools());
    }

    private static function log(): RecordingLog
    {
        return new RecordingLog();
    }

    public function testASearchIsLoggedWithItsAnswer(): void
    {
        $log = self::log();
        self::call(CompactMode::SEARCH, ['action' => 'show', 'object' => 'ticket'], $log);

        self::assertCount(1, $log->searches);
        self::assertSame('ticket', $log->searches[0]['query']->object);
        self::assertSame('acme_get_ticket', $log->searches[0]['result']->tools[0]->name ?? null);
    }

    public function testWordsNoToolUsesReachTheLog(): void
    {
        $log = self::log();
        self::call(CompactMode::SEARCH, ['query' => 'zzqx'], $log);

        self::assertSame(['zzqx'], $log->searches[0]['result']->unmatched);
        self::assertSame([], $log->searches[0]['result']->tools);
    }

    public function testWithALogAndNoLearningTheRunIsTiedToItsSearch(): void
    {
        $log = self::log();
        $answer = self::call(CompactMode::SEARCH, ['action' => 'show', 'object' => 'ticket'], $log)->structuredContent;
        self::assertIsArray($answer);
        self::assertIsString($answer['search_id'] ?? null);

        self::call(CompactMode::RUN_READ, ['name' => 'acme_get_ticket', 'arguments' => [], 'search_id' => $answer['search_id']], $log);

        self::assertSame([['tool' => 'acme_get_ticket', 'succeeded' => true, 'searchId' => $answer['search_id']]], $log->runs);
        self::assertSame($log->searches[0]['result']->searchId, $log->runs[0]['searchId']);
    }

    public function testWithoutALogOrLearningNoSearchIdIsGiven(): void
    {
        $answer = (new Discovery(new CompactMode(), new RecordingGate()))
            ->call(CompactMode::SEARCH, ['action' => 'show', 'object' => 'ticket'], RecordingGate::fixtureTools())->structuredContent;

        self::assertIsArray($answer);
        self::assertArrayNotHasKey('search_id', $answer);
    }

    public function testTheLogLeavesLearningAsItWas(): void
    {
        $store = new MemoryLearningStore();
        $log = self::log();
        $answer = self::call(CompactMode::SEARCH, ['action' => 'show', 'object' => 'ticket'], $log, null, $store)->structuredContent;
        self::assertIsArray($answer);
        self::assertIsString($answer['search_id'] ?? null);

        self::call(CompactMode::RUN_READ, ['name' => 'acme_get_ticket', 'arguments' => [], 'search_id' => $answer['search_id']], $log, null, $store);

        self::assertArrayHasKey('ticket', $store->load());
        self::assertCount(1, $log->runs);
    }

    public function testARunWithoutASearchIsLoggedWithNoId(): void
    {
        $log = self::log();
        self::call(CompactMode::RUN_READ, ['name' => 'acme_get_ticket', 'arguments' => []], $log);

        self::assertSame([['tool' => 'acme_get_ticket', 'succeeded' => true, 'searchId' => null]], $log->runs);
    }

    public function testASearchIdNoSearchCouldHaveMadeIsNotPassedOn(): void
    {
        foreach (["<script>\n", str_repeat('a', 2049), ' abc', "abc\n"] as $forged) {
            $log = self::log();
            self::call(CompactMode::RUN_READ, ['name' => 'acme_get_ticket', 'arguments' => [], 'search_id' => $forged], $log);

            self::assertCount(1, $log->runs);
            self::assertNull($log->runs[0]['searchId'], var_export($forged, true));
        }
    }

    public function testAHiddenOrRefusedToolIsNeverLogged(): void
    {
        $log = self::log();
        $gate = new RecordingGate(['acme_get_ticket'], ['acme_get_file']);
        self::call(CompactMode::RUN_READ, ['name' => 'acme_get_ticket', 'arguments' => []], $log, $gate);
        self::call(CompactMode::RUN_READ, ['name' => 'acme_get_file', 'arguments' => []], $log, $gate);
        $answer = self::call(CompactMode::SEARCH, ['action' => 'show', 'object' => 'ticket'], $log, $gate)->structuredContent;

        self::assertSame([], $log->runs);
        self::assertIsArray($answer);
        foreach ($log->searches as $search) {
            self::assertNotContains('acme_get_ticket', array_map(static fn(Tool $tool): string => $tool->name, $search['result']->tools));
        }
    }

    public function testAFailedRunIsLoggedAsFailed(): void
    {
        $log = self::log();
        // A read tool sent to the change runner never reaches the gate, so
        // it is not a run: the log hears of what the gate ran, nothing else.
        self::call(CompactMode::RUN_WRITE, ['name' => 'acme_get_ticket', 'arguments' => []], $log);
        self::assertSame([], $log->runs);

        $gate = new FailingGate();
        self::call(CompactMode::RUN_READ, ['name' => 'acme_get_ticket', 'arguments' => []], $log, $gate);
        self::assertSame([['tool' => 'acme_get_ticket', 'succeeded' => false, 'searchId' => null]], $log->runs);
    }

    public function testTheGatewayLogsAsTheCompactModeDoes(): void
    {
        $log = self::log();
        $discovery = new Discovery(new GatewayMode(), new RecordingGate(), new SearchSetup(log: $log));
        $answer = $discovery->call(GatewayMode::NAME, ['operation' => GatewayMode::SEARCH, 'action' => 'show', 'object' => 'ticket'], RecordingGate::fixtureTools())->structuredContent;
        self::assertIsArray($answer);
        self::assertIsString($answer['search_id'] ?? null);

        $discovery->call(GatewayMode::NAME, ['operation' => GatewayMode::RUN, 'name' => 'acme_get_ticket', 'arguments' => [], 'search_id' => $answer['search_id']], RecordingGate::fixtureTools());

        self::assertCount(1, $log->searches);
        self::assertSame($answer['search_id'], $log->runs[0]['searchId'] ?? null);
    }
}
