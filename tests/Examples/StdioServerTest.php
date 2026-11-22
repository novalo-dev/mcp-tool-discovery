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

    public function testARoleGrantedInDirectModeTellsTheClientItsListChanged(): void
    {
        $lines = self::liveSession('direct');

        self::assertSame(['tools' => ['listChanged' => true]], $lines[0][0]['result']['capabilities']);
        self::assertSame(['notes_list', 'notes_get', 'notes_add'], array_column($lines[1][0]['result']['tools'], 'name'));
        // The role changes before the ping: the notification comes first,
        // and only once, and the list asked for again holds the tool.
        self::assertSame([['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']], array_slice($lines[2], 0, -1));
        self::assertSame([], $lines[2][1]['result']);
        self::assertCount(1, $lines[3]);
        self::assertSame(['notes_list', 'notes_get', 'notes_add', 'notes_delete'], array_column($lines[4][0]['result']['tools'], 'name'));
        self::assertCount(1, $lines[5]);
        // Taken back, the tool goes, and the client is told again.
        self::assertSame('notifications/tools/list_changed', $lines[6][0]['method']);
    }

    public function testARoleGrantedInCompactModeLeavesTheListAsItWas(): void
    {
        $lines = self::liveSession('compact');

        foreach ([2, 3, 5, 6] as $step) {
            self::assertCount(1, $lines[$step], 'No notification: the compact tools never moved.');
        }
        self::assertSame(
            array_column($lines[1][0]['result']['tools'], 'name'),
            array_column($lines[4][0]['result']['tools'], 'name'),
        );
        self::assertSame('notes_delete', $lines[5][0]['result']['structuredContent']['tools'][0]['name']);
    }

    /**
     * Runs the server with its role in a file and changes the file between
     * two messages, as an administrator would while the client is connected.
     * The steps: initialize, list as a reader, ping twice as an admin, list
     * again, search for deletion, ping as a reader again. Each step gives the lines
     * the server wrote before its reply, then the reply.
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function liveSession(string $mode): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'role');
        file_put_contents($file, 'reader');
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/docs/examples/stdio-server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['MCP_NOTES_ROLE_FILE' => $file, 'MCP_NOTES_MODE' => $mode] + getenv(),
        );
        self::assertIsResource($process);

        $steps = [
            ['reader', self::INITIALIZE],
            ['reader', ['method' => 'tools/list']],
            ['admin', ['method' => 'ping']],
            ['admin', ['method' => 'ping']],
            ['admin', ['method' => 'tools/list']],
            ['admin', ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'delete', 'object' => 'note']]]],
            ['reader', ['method' => 'ping']],
        ];
        $lines = [];
        foreach ($steps as $i => [$role, $message]) {
            file_put_contents($file, $role);
            fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => $i + 1] + $message, JSON_THROW_ON_ERROR) . "\n");
            $lines[$i] = [];
            do {
                $line = fgets($pipes[1]);
                self::assertIsString($line, 'The server stopped before its reply.');
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $lines[$i][] = $decoded;
            } while (($decoded['id'] ?? null) !== $i + 1);
        }

        fclose($pipes[0]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        unlink($file);

        return $lines;
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
