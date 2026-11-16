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
     *
     * @return array<int, array<string, mixed>>
     */
    private static function exchange(string $script, array $messages, string $role): array
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
            ['MCP_NOTES_ROLE' => $role] + getenv(),
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
}
