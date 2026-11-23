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

/**
 * Talks to an example server over stdio as a client would. Shared by the
 * server written by hand and the one on the official SDK, so both are held
 * to the same exchange.
 */
trait StdioSession
{
    /**
     * Runs a server script in its own process, sends each message with its
     * position as id (none for a notification), and returns the replies by id.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, string>      $env      more of the server's settings
     *
     * @return array<int, array<string, mixed>>
     */
    private static function exchange(string $script, array $messages, string $role, array $env = []): array
    {
        $input = '';
        foreach ($messages as $i => $message) {
            $notification = ($message['notification'] ?? false) === true;
            unset($message['notification']);
            $input .= json_encode(['jsonrpc' => '2.0'] + ($notification ? [] : ['id' => $i + 1]) + $message, JSON_THROW_ON_ERROR) . "\n";
        }

        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['MCP_NOTES_ROLE' => $role] + $env + getenv(),
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);

        $replies = [];
        foreach (explode("\n", trim($output)) as $line) {
            $reply = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($reply);
            self::assertSame('2.0', $reply['jsonrpc']);
            self::assertIsInt($reply['id']);
            $replies[$reply['id']] = $reply;
        }

        return $replies;
    }

    /**
     * Holds a server to what its search learns over one session: a run with
     * the search_id lifts its tool for the next search, a failed run teaches
     * nothing. "open a note" finds the tool that adds one first, by "a note";
     * the client reads the note instead.
     *
     * The id comes from a first process: it holds only the words of the
     * request, so it is the same in the next one, where the learning is kept.
     *
     * @param list<array<string, mixed>> $opening the handshake of the server
     */
    private static function assertARunTeachesTheSession(string $script, array $opening): void
    {
        $search = ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['query' => 'open a note']]];
        $at = count($opening) + 1;

        $first = self::exchange($script, [...$opening, $search], 'reader')[$at]['result']['structuredContent'];
        self::assertSame('notes_add', $first['tools'][0]['name']);
        self::assertIsString($first['search_id']);

        $read = static fn(int $id): array => ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => [
            'name' => 'notes_get',
            'arguments' => ['id' => $id],
            'search_id' => $first['search_id'],
        ]]];
        $replies = self::exchange($script, [...$opening, $read(9), $search, $read(1), $search], 'reader');

        self::assertTrue($replies[$at]['result']['isError']);
        self::assertSame('notes_add', $replies[$at + 1]['result']['structuredContent']['tools'][0]['name']);
        self::assertArrayNotHasKey('isError', $replies[$at + 2]['result']);
        self::assertSame('notes_get', $replies[$at + 3]['result']['structuredContent']['tools'][0]['name']);
    }
}
