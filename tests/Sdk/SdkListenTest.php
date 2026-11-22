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

namespace Novalo\McpToolDiscovery\Tests\Sdk;

use Novalo\McpToolDiscovery\Tests\Examples\LocalServer;
use PHPUnit\Framework\TestCase;

/**
 * Opens `subscriptions/listen` streams of the stateless revision on
 * docs/examples/sdk/http-server.php, then changes rights with
 * docs/examples/sdk/grant.php, as an administrator would while clients are
 * connected. Every change is published on the one bus all callers share;
 * each stream is told only when its own caller's list moved.
 */
final class SdkListenTest extends TestCase
{
    private const CHANGED = 'notifications/tools/list_changed';

    private string $data = '';
    private string $grants = '';

    private ?LocalServer $server = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/docs/examples/sdk/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/sdk first.');
        }
        $this->data = sys_get_temp_dir() . '/mcp-sdk-listen-' . bin2hex(random_bytes(6));
        // Made here, for the grants file the server and grant.php both read.
        mkdir($this->data, 0o700, true);
        $this->grants = $this->data . '/grants.json';
        file_put_contents($this->grants, '{}');
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        LocalServer::remove($this->data);
    }

    public function testAStreamIsToldOnlyWhenItsOwnCallersListChanges(): void
    {
        $address = $this->start('direct');
        $reader = $this->listen($address, 'reader-token');
        $admin = $this->listen($address, 'admin-token');

        // The admin already has the admin's rights: published, but no list moved.
        $this->grant('admin', 'admin');
        self::assertSame([], self::read($reader, 1.5));

        $this->grant('reader', 'admin');
        self::assertSame([self::CHANGED], self::read($reader, 3.0));

        $this->grant('reader');
        // Told once for each change, and the admin, whose list never moved,
        // was never told of the reader's.
        self::assertSame([self::CHANGED], self::read($reader, 3.0));
        self::assertSame([], self::read($admin, 0.5));
    }

    public function testInCompactModeARightGrantedTellsNoOne(): void
    {
        $address = $this->start('compact');
        $reader = $this->listen($address, 'reader-token');

        $this->grant('reader', 'admin');

        // The four tools of the compact mode stay the same; what moved is
        // only what the search finds.
        self::assertSame([], self::read($reader, 2.0));
    }

    private function start(string $mode): string
    {
        $this->server = LocalServer::start(
            static fn(string $address): array => [PHP_BINARY, '-S', $address, dirname(__DIR__, 2) . '/docs/examples/sdk/http-server.php'],
            // A stream holds a worker for as long as it is open.
            $this->environment() + ['MCP_NOTES_MODE' => $mode, 'PHP_CLI_SERVER_WORKERS' => '4'],
        );

        return $this->server->address;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return ['MCP_NOTES_DATA' => $this->data, 'MCP_NOTES_GRANTS' => $this->grants];
    }

    /**
     * Opens a stream for this token and waits for its acknowledgment, so
     * that a change made after this returns is one the stream compares.
     *
     * @return resource
     */
    private function listen(string $address, string $token)
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'subscriptions/listen', 'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
                'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
            'notifications' => ['toolsListChanged' => true],
        ]], JSON_THROW_ON_ERROR);
        $socket = stream_socket_client('tcp://' . $address, $code, $text, 5);
        self::assertIsResource($socket);
        fwrite($socket, implode("\r\n", [
            'POST /mcp HTTP/1.1',
            'Host: ' . $address,
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'Authorization: Bearer ' . $token,
            'MCP-Protocol-Version: 2026-07-28',
            'Mcp-Method: subscriptions/listen',
            'Content-Length: ' . strlen($body),
            'Connection: close',
            '',
            $body,
        ]));
        stream_set_blocking($socket, false);

        $deadline = microtime(true) + 10;
        $seen = '';
        while (microtime(true) < $deadline && !str_contains($seen, 'notifications/subscriptions/acknowledged')) {
            $seen .= (string) fread($socket, 8192);
            usleep(20000);
        }
        self::assertStringContainsString('notifications/subscriptions/acknowledged', $seen);
        self::assertStringContainsString('"toolsListChanged":true', $seen);

        return $socket;
    }

    private function grant(string $role, ?string $rights = null): void
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/docs/examples/sdk/grant.php', $role];
        if ($rights !== null) {
            $command[] = $rights;
        }
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment() + getenv());
        self::assertIsResource($process);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
    }

    /**
     * The methods of the notifications a stream carries for this long.
     *
     * @param resource $socket
     *
     * @return list<string>
     */
    private static function read($socket, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $seen = '';
        while (microtime(true) < $deadline) {
            $seen .= (string) fread($socket, 8192);
            usleep(20000);
        }
        preg_match_all('/^data: (.+)$/m', $seen, $frames);
        $methods = [];
        foreach ($frames[1] as $frame) {
            $message = json_decode($frame, true);
            if (is_array($message) && is_string($message['method'] ?? null)) {
                $methods[] = $message['method'];
            }
        }

        return $methods;
    }
}
