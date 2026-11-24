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

    public function testTheInstructionsSayHowTheModeReachesTheTools(): void
    {
        $instructions = self::session([self::INITIALIZE])[1]['result']['instructions'] ?? null;

        self::assertIsString($instructions);
        self::assertStringStartsWith('Notes kept for the team.', $instructions);
        self::assertStringContainsString('notes_search_tools', $instructions);
        self::assertStringContainsString('notes_run_write_batch', $instructions);
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

    public function testTheLogTiesEachRunToTheSearchBeforeIt(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'log');
        $search = ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['query' => 'open a note']]];

        try {
            $first = self::session([self::INITIALIZE, $search], 'reader', ['MCP_NOTES_SEARCH_LOG' => $file]);
            $id = $first[2]['result']['structuredContent']['search_id'];
            self::assertIsString($id);
            self::session([
                self::INITIALIZE,
                ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 1], 'search_id' => $id]]],
                // Hidden from a reader: the log learns nothing of it.
                ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_delete', 'arguments' => ['id' => 1]]]],
                ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 9], 'search_id' => 'not an id']]],
            ], 'reader', ['MCP_NOTES_SEARCH_LOG' => $file]);

            $lines = array_map(
                static fn(string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                explode("\n", trim((string) file_get_contents($file))),
            );
        } finally {
            unlink($file);
        }

        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertIsArray($line);
            self::assertIsString($line['at'] ?? null);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $line['at']);
        }
        unset($lines[0]['at'], $lines[1]['at'], $lines[2]['at']);
        self::assertSame(['search' => $id, 'fields' => ['text' => 'open a note'], 'found' => ['notes_add', 'notes_get'], 'unmatched' => ['open']], $lines[0]);
        self::assertSame(['search' => $id, 'ran' => 'notes_get', 'succeeded' => true], $lines[1]);
        self::assertSame(['search' => null, 'ran' => 'notes_get', 'succeeded' => false], $lines[2]);
    }

    public function testTheReportReadsTheMissesOfTheLogTheServerWrote(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'log');
        $search = static fn(array $arguments): array => ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => $arguments]];

        try {
            $first = self::session([self::INITIALIZE, $search(['query' => 'open a note']), $search(['query' => 'zzqx'])], 'reader', ['MCP_NOTES_SEARCH_LOG' => $file]);
            $id = $first[2]['result']['structuredContent']['search_id'];
            self::assertIsString($id);
            self::session([
                self::INITIALIZE,
                ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 1], 'search_id' => $id]]],
            ], 'reader', ['MCP_NOTES_SEARCH_LOG' => $file]);
            // A line cut short, as a crash leaves it, is counted and skipped.
            file_put_contents($file, '{"search":', FILE_APPEND);

            $report = self::report($file);
        } finally {
            unlink($file);
        }

        $lines = explode("\n", rtrim($report));
        self::assertSame('2 searches, 1 runs (0 failed), 1 unreadable lines', $lines[0]);
        self::assertSame('0 of 1 successful runs after a search ran the first tool found', $lines[1]);
        self::assertContains(\sprintf('  %-20s %4d  %s', 'open', 1, 'notes_get (1)'), $lines);
        self::assertContains(\sprintf('  %-20s %4d  %s', 'zzqx', 1, '-'), $lines);
        self::assertContains(\sprintf('  %-20s %-10s %4d  %s', 'notes_get', '2nd', 1, 'text: open a note'), $lines);
        self::assertSame(\sprintf('  %4d  %s', 1, 'text: zzqx'), end($lines));
    }

    public function testTheReportPrintsARequestWithoutItsControlCharacters(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'log');

        try {
            self::session([
                self::INITIALIZE,
                ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['query' => "zzqx\u{1b}[2J\u{9b}1m \u{202e}qqzz\u{7} é"]]],
            ], 'reader', ['MCP_NOTES_SEARCH_LOG' => $file]);
            $report = self::report($file);
        } finally {
            unlink($file);
        }

        $lines = explode("\n", rtrim($report));
        self::assertSame(\sprintf('  %4d  %s', 1, 'text: zzqx?[2J?1m ?qqzz? é'), end($lines));
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

    public function testARoleGrantedInGroupedModeAddsTheDeletionToTheChangeTool(): void
    {
        $lines = self::liveSession('grouped', ['name' => 'notes', 'arguments' => ['describe' => ['notes_delete']]]);

        $reader = $lines[1][0]['result']['tools'];
        self::assertSame(['notes', 'notes_change'], array_column($reader, 'name'));
        self::assertSame(['notes_list', 'notes_get'], $reader[0]['inputSchema']['properties']['action']['enum']);
        self::assertSame(['notes_add'], $reader[1]['inputSchema']['properties']['action']['enum']);
        self::assertTrue($reader[0]['annotations']['readOnlyHint']);
        self::assertTrue($reader[1]['annotations']['destructiveHint']);
        // The change tool now names one more action: the list moved, and
        // the client is told before the reply to the ping.
        self::assertSame('notifications/tools/list_changed', $lines[2][0]['method']);
        self::assertCount(1, $lines[3]);
        $admin = $lines[4][0]['result']['tools'];
        self::assertSame(['notes_add', 'notes_delete'], $admin[1]['inputSchema']['properties']['action']['enum']);
        // Describing a change goes through the read tool, so it never asks
        // the person anything.
        $described = $lines[5][0]['result']['structuredContent']['tools'][0];
        self::assertSame(['notes_delete', 'notes_change'], [$described['name'], $described['run_with']]);
        self::assertSame('notifications/tools/list_changed', $lines[6][0]['method']);
    }

    public function testAReaderAddsAndReadsANoteThroughTheGroupTools(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_change', 'arguments' => ['action' => 'notes_add', 'arguments' => ['text' => 'Grouped']]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes', 'arguments' => ['action' => 'notes_get', 'arguments' => ['id' => 2]]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes', 'arguments' => ['action' => 'notes_add', 'arguments' => ['text' => 'Sent to the read tool']]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_change', 'arguments' => ['action' => 'notes_delete', 'arguments' => ['id' => 1]]]],
        ], env: ['MCP_NOTES_MODE' => 'grouped']);

        // The example starts with one note, so the one added is the second.
        self::assertSame(['id' => 2], $replies[2]['result']['structuredContent']);
        self::assertSame('Grouped', $replies[3]['result']['content'][0]['text']);
        // A change sent to the read tool is refused before the gate is
        // asked, with the tool that runs it.
        self::assertTrue($replies[4]['result']['isError']);
        self::assertStringContainsString('notes_change', $replies[4]['result']['content'][0]['text']);
        // A tool the gate hides from a reader is not one of the group's
        // actions, and nothing tells the reader it exists.
        self::assertTrue($replies[5]['result']['isError']);
        self::assertStringNotContainsString('Deletes', json_encode($replies[5], JSON_THROW_ON_ERROR));
    }

    public function testARoleGrantedInGatewayModeLeavesTheOneToolAsItWas(): void
    {
        $lines = self::liveSession('gateway', ['name' => 'notes_use_tools', 'arguments' => ['operation' => 'search', 'action' => 'delete', 'object' => 'note']]);

        $tools = $lines[1][0]['result']['tools'];
        self::assertSame(['notes_use_tools'], array_column($tools, 'name'));
        // A reader may already add a note, so the one tool is destructive
        // before the role changes, and the admin's list is the same.
        self::assertTrue($tools[0]['annotations']['destructiveHint']);
        foreach ([2, 3, 5, 6] as $step) {
            self::assertCount(1, $lines[$step], 'No notification: the gateway never moved.');
        }
        self::assertSame($tools, $lines[4][0]['result']['tools']);
        // The search finds the tool the role now shows, marked as a change,
        // since the gateway's own annotations cannot tell.
        $found = $lines[5][0]['result']['structuredContent']['tools'][0];
        self::assertSame('notes_delete', $found['name']);
        self::assertTrue($found['changes']);
    }

    public function testAToolFoundThroughTheGatewayRunsThroughIt(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_use_tools', 'arguments' => ['operation' => 'search', 'action' => 'add', 'object' => 'note']]],
        ], env: ['MCP_NOTES_MODE' => 'gateway']);
        $found = $replies[2]['result']['structuredContent'];
        self::assertSame('notes_add', $found['tools'][0]['name']);
        self::assertTrue($found['tools'][0]['changes']);
        self::assertArrayNotHasKey('changes', array_column($found['tools'], null, 'name')['notes_list'] ?? []);

        $replies = self::session([
            self::INITIALIZE,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_use_tools', 'arguments' => ['operation' => 'run', 'name' => 'notes_add', 'arguments' => ['text' => 'Through the gateway']]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_use_tools', 'arguments' => ['operation' => 'run', 'name' => 'notes_get', 'arguments' => ['id' => 2]]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_use_tools', 'arguments' => ['operation' => 'run', 'name' => 'notes_delete', 'arguments' => ['id' => 1]]]],
        ], env: ['MCP_NOTES_MODE' => 'gateway']);
        self::assertSame(['id' => 2], $replies[2]['result']['structuredContent']);
        self::assertSame('Through the gateway', $replies[3]['result']['content'][0]['text']);
        // Hidden from a reader: refused as a name that does not exist.
        self::assertTrue($replies[4]['result']['isError']);
        self::assertStringNotContainsString('Deletes', json_encode($replies[4], JSON_THROW_ON_ERROR));
    }

    /**
     * Runs the server with its role in a file and changes the file between
     * two messages, as an administrator would while the client is connected.
     * The steps: initialize, list as a reader, ping twice as an admin, list
     * again, the call given (a search for deletion in compact mode), ping as
     * a reader again. Each step gives the lines the server wrote before its
     * reply, then the reply.
     *
     * @param array<string, mixed> $call the params of the tools/call step
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function liveSession(string $mode, array $call = ['name' => 'notes_search_tools', 'arguments' => ['action' => 'delete', 'object' => 'note']]): array
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
            ['admin', ['method' => 'tools/call', 'params' => $call]],
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
     * The report the example prints for a log file.
     */
    private static function report(string $file): string
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/docs/examples/search-log-report.php', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $report = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, string>      $env
     *
     * @return array<int, array<string, mixed>>
     */
    private static function session(array $messages, string $role = 'reader', array $env = []): array
    {
        return self::exchange(dirname(__DIR__, 2) . '/docs/examples/stdio-server.php', $messages, $role, $env);
    }
}
