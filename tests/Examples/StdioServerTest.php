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

namespace Novalo\McpToolDiscovery\Tests\Examples;

use PHPUnit\Framework\TestCase;

/**
 * Runs docs/examples/stdio-server.php as a client would, in its own process,
 * so the example a host copies is held to the library as it is today.
 */
final class StdioServerTest extends TestCase
{
    use StdioSession;

    private const INITIALIZE = ['method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]];

    public function testTheClientSeesTheCompactToolsAndNegotiatesItsRevision(): void
    {
        $replies = self::session([self::INITIALIZE, ['method' => 'tools/list']]);

        self::assertSame('2025-06-18', $replies[1]['result']['protocolVersion']);
        self::assertSame(
            ['notes_search_tools', 'notes_run_read_tool', 'notes_run_write_tool', 'notes_run_write_batch'],
            array_column($replies[2]['result']['tools'], 'name'),
        );
    }

    public function testAnUnknownRevisionGetsTheLatestHandshakeRevision(): void
    {
        $replies = self::session([['method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]]);

        self::assertSame('2025-11-25', $replies[1]['result']['protocolVersion']);
    }

    public function testAToolFoundBySearchRunsThroughTheWriteRunner(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'add', 'object' => 'note']]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_add', 'arguments' => ['text' => 'Renew the certificate.']]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 2]]]],
        ]);

        self::assertSame('notes_add', $replies[2]['result']['structuredContent']['tools'][0]['name']);
        self::assertSame(['id' => 2], $replies[3]['result']['structuredContent']);
        self::assertSame('Renew the certificate.', $replies[4]['result']['content'][0]['text']);
    }

    public function testARunFoundBySearchLiftsItsToolForTheSession(): void
    {
        self::assertARunTeachesTheSession(dirname(__DIR__, 2) . '/docs/examples/stdio-server.php', [self::INITIALIZE]);
    }

    public function testAToolTheGateHidesIsUnknownUntilTheRoleAllowsIt(): void
    {
        $delete = ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_delete', 'arguments' => ['id' => 1]]]];

        $reader = self::session([self::INITIALIZE, $delete]);
        self::assertTrue($reader[2]['result']['isError']);
        self::assertSame('Unknown tool: notes_delete.', $reader[2]['result']['content'][0]['text']);

        $admin = self::session([self::INITIALIZE, $delete], 'admin');
        self::assertArrayNotHasKey('isError', $admin[2]['result']);
        self::assertSame('Deleted.', $admin[2]['result']['content'][0]['text']);
    }

    public function testABatchReportsEachItemAndTheGateRefusesOneOfThem(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_batch', 'arguments' => [
                'name' => 'notes_add',
                'items' => [['text' => 'First.'], ['text' => str_repeat('x', 501)], ['text' => 'Third.']],
            ]]],
        ]);

        $batch = $replies[2]['result']['structuredContent'];
        self::assertSame(2, $batch['succeeded']);
        self::assertSame(1, $batch['failed']);
    }

    public function testTheListGivesTheStartOfEachNoteWhateverItsAlphabet(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_add', 'arguments' => ['text' => 'a' . str_repeat('é', 50)]]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_list']]],
        ]);

        self::assertSame(['id' => 2, 'text' => 'a' . str_repeat('é', 39)], $replies[3]['result']['structuredContent']['notes'][1]);
    }

    public function testNotificationsGetNoAnswerAndMistakesGetJsonRpcErrors(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            ['method' => 'notifications/initialized', 'notification' => true],
            ['method' => 'resources/list'],
            ['method' => 'tools/call', 'params' => ['arguments' => []]],
        ]);

        self::assertArrayNotHasKey(2, $replies);
        self::assertSame(-32601, $replies[3]['error']['code']);
        self::assertSame(-32602, $replies[4]['error']['code']);
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private static function session(array $messages, string $role = 'reader'): array
    {
        return self::exchange(dirname(__DIR__, 2) . '/docs/examples/stdio-server.php', $messages, $role);
    }
}
