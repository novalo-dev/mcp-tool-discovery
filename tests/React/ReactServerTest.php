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

namespace Novalo\McpToolDiscovery\Tests\React;

use Novalo\McpToolDiscovery\Tests\Examples\HttpServerTestCase;

/**
 * Runs docs/examples/react/server.php, one process that serves every
 * request, and asks it all the HTTP server of PHP-FPM is asked. It needs
 * ReactPHP installed in docs/examples/react, so it is a test suite of its
 * own, run by its own CI job at the version the example pins.
 */
final class ReactServerTest extends HttpServerTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/docs/examples/react/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/react first.');
        }
        parent::setUpBeforeClass();
    }

    protected static function command(string $address): array
    {
        return [PHP_BINARY, dirname(__DIR__, 2) . '/docs/examples/react/server.php'];
    }

    // The process serves every caller in turn with the same index cache and
    // the same notes: what one caller may see must still never reach the
    // next, whichever order they come in.
    public function testCallersTakingTurnsInOneProcessEachSeeTheirOwnTools(): void
    {
        $search = ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'delete', 'object' => 'note']]];
        $found = static fn(string $token): array => array_column(self::post($search, $token)[1]['result']['structuredContent']['tools'] ?? [], 'name');

        foreach (['admin-token', 'reader-token', 'admin-token', 'reader-token'] as $token) {
            $names = $found($token);
            if ($token === 'admin-token') {
                self::assertContains('notes_delete', $names);
            } else {
                self::assertNotContains('notes_delete', $names);
            }
        }
    }

    // In memory, a note lives as long as the process, with no file at all.
    public function testANoteIsKeptInMemoryWithNothingWrittenToDisk(): void
    {
        [, $added] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_add', 'arguments' => ['text' => 'Rotate the keys.']]]], 'reader-token');
        $id = $added['result']['structuredContent']['id'] ?? null;
        self::assertIsInt($id);

        [, $read] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => $id]]]], 'reader-token');
        self::assertSame('Rotate the keys.', $read['result']['content'][0]['text'] ?? null);
        self::assertFalse(is_dir(self::$data));
    }

    // The list moves under a connected client: the process sees it between
    // two requests and tells that client on its stream, once, and no other
    // caller can listen on the session or use it.
    public function testAChangeOfRightsIsSentOnceOnTheStreamOfTheSessionThatListed(): void
    {
        $grants = sys_get_temp_dir() . '/mcp-react-grants-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($grants, '{}');
        [$process, $url] = self::start(['MCP_NOTES_MODE' => 'direct', 'MCP_NOTES_GRANTS' => $grants]);

        try {
            [$headers, $initialized] = self::send($url, 'POST', ['Authorization: Bearer reader-token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]);
            self::assertTrue($initialized['result']['capabilities']['tools']['listChanged'] ?? null);
            $session = $headers['mcp-session-id'] ?? '';
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $session);
            $reader = ['Authorization: Bearer reader-token', 'Mcp-Session-Id: ' . $session, 'MCP-Protocol-Version: 2025-11-25'];

            [, $listed] = self::send($url, 'POST', $reader, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
            self::assertNotContains('notes_delete', array_column($listed['result']['tools'] ?? [], 'name'));

            // The session is the reader's: an admin cannot use it, and an id
            // the server never gave is no session.
            self::assertSame('404', self::send($url, 'POST', ['Authorization: Bearer admin-token', 'Mcp-Session-Id: ' . $session], ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])[0]['status']);
            self::assertSame('404', self::send($url, 'GET', ['Authorization: Bearer admin-token', 'Mcp-Session-Id: ' . $session, 'Accept: text/event-stream'], null)[0]['status']);
            self::assertSame('404', self::send($url, 'GET', ['Authorization: Bearer reader-token', 'Mcp-Session-Id: ' . str_repeat('0', 32), 'Accept: text/event-stream'], null)[0]['status']);

            $stream = self::listen($url, [...$reader, 'Accept: text/event-stream']);
            // Nothing changed yet: nothing is sent.
            self::assertSame([], self::events($stream, 0.8));

            file_put_contents($grants, '{"reader": "admin"}');
            self::assertSame([['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']], self::events($stream, 1.5));
            // Once, not at every look of the timer.
            self::assertSame([], self::events($stream, 0.8));
            fclose($stream);

            [, $again] = self::send($url, 'POST', $reader, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list']);
            self::assertContains('notes_delete', array_column($again['result']['tools'] ?? [], 'name'));
        } finally {
            proc_terminate($process);
            proc_close($process);
            @unlink($grants);
        }
    }

    /**
     * A server of its own, for a test that needs other settings.
     *
     * @param array<string, string> $env
     *
     * @return array{resource, string}
     */
    private static function start(array $env): array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $process = proc_open(
            self::command($address),
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['MCP_LISTEN' => $address] + $env + getenv(),
        );
        self::assertIsResource($process);
        for ($i = 0; $i < 100; ++$i) {
            $socket = @stream_socket_client('tcp://' . $address, $code, $text, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return [$process, 'http://' . $address . '/mcp'];
            }
            usleep(50000);
        }
        self::fail('The server did not start.');
    }

    /**
     * @param list<string>              $headers
     * @param array<string, mixed>|null $message
     *
     * @return array{array<string, string>, array<mixed>} the headers by lower-case name, with the status under "status", and the decoded body
     */
    private static function send(string $url, string $method, array $headers, ?array $message): array
    {
        $stream = fopen($url, 'r', false, stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", ['Content-Type: application/json', ...$headers]),
            'content' => $message === null ? '' : json_encode($message, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]));
        self::assertIsResource($stream);
        $answer = (string) stream_get_contents($stream);
        $meta = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);

        return [self::headers(is_array($meta) ? $meta : []), $answer === '' ? [] : (array) json_decode($answer, true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * The stream of server messages of a session, left open.
     *
     * @param list<string> $headers
     *
     * @return resource
     */
    private static function listen(string $url, array $headers)
    {
        $stream = fopen($url, 'r', false, stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]));
        self::assertIsResource($stream);
        $meta = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $headers = self::headers(is_array($meta) ? $meta : []);
        self::assertSame('200', $headers['status']);
        self::assertStringStartsWith('text/event-stream', $headers['content-type'] ?? '');
        stream_set_blocking($stream, false);

        return $stream;
    }

    /**
     * The messages the stream carries within the next few seconds.
     *
     * @param resource $stream
     *
     * @return list<mixed>
     */
    private static function events($stream, float $seconds): array
    {
        $events = [];
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $line = fgets($stream);
            if ($line === false) {
                usleep(20000);
                continue;
            }
            if (str_starts_with($line, 'data: ')) {
                $events[] = json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $events;
    }

    /**
     * @param array<mixed> $lines the wrapper's header lines, the status line first
     *
     * @return array<string, string>
     */
    private static function headers(array $lines): array
    {
        $headers = ['status' => is_string($lines[0] ?? null) ? (explode(' ', $lines[0])[1] ?? '') : ''];
        foreach (array_slice($lines, 1) as $line) {
            if (is_string($line) && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }

        return $headers;
    }
}
