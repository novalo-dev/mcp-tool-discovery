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
 * What a client sees of the notes server over HTTP, whatever runs it. Each
 * server that answers from docs/examples/http.php passes all of it, so a
 * host picks the one that fits how its PHP runs, not one that answers
 * differently.
 */
abstract class HttpServerTestCase extends TestCase
{
    private const READER = 'reader-token';
    private const ADMIN = 'admin-token';

    /** @var resource|null */
    private static $server;
    private static string $url = '';
    protected static string $data = '';

    public static function setUpBeforeClass(): void
    {
        // A free port, taken from the system rather than guessed.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        self::$data = sys_get_temp_dir() . '/mcp-http-example-' . bin2hex(random_bytes(6));
        self::$url = 'http://' . $address . '/mcp';
        $server = proc_open(
            static::command($address),
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['MCP_NOTES_DATA' => self::$data, 'MCP_LISTEN' => $address] + getenv(),
        );
        self::assertIsResource($server);
        self::$server = $server;

        for ($i = 0; $i < 100; ++$i) {
            $socket = @stream_socket_client('tcp://' . $address, $code, $text, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        self::fail('The server did not start.');
    }

    /**
     * The command that starts the server on this address.
     *
     * @return list<string>
     */
    abstract protected static function command(string $address): array;

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        foreach (glob(self::$data . '/{,index/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir(self::$data . '/index');
        @rmdir(self::$data);
    }

    public function testInitializeNegotiatesAndTheHeaderCarriesTheRevisionAfterwards(): void
    {
        [$status, $body] = self::post(['method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], self::READER, null);
        self::assertSame(200, $status);
        self::assertSame('2025-06-18', $body['result']['protocolVersion'] ?? null);

        [, $listed] = self::post(['method' => 'tools/list'], self::READER, '2025-06-18');
        self::assertSame(
            ['notes_search_tools', 'notes_run_read_tool', 'notes_run_write_tool', 'notes_run_write_batch'],
            array_column($listed['result']['tools'], 'name'),
        );
        // 2025-06-18 knows titles: the answer was shaped for the header's revision.
        self::assertArrayHasKey('title', $listed['result']['tools'][0]);

        [, $old] = self::post(['method' => 'tools/list'], self::READER, null);
        self::assertArrayNotHasKey('title', $old['result']['tools'][0]);
    }

    public function testAnUnknownRevisionInTheHeaderIsRefused(): void
    {
        [$status, $body] = self::post(['method' => 'tools/list'], self::READER, '1999-01-01');

        self::assertSame(400, $status);
        self::assertSame(-32600, $body['error']['code'] ?? null);
    }

    public function testTheStatelessRevisionIsDiscoveredAndNamedOnEveryRequest(): void
    {
        [$status, $discovered] = self::post(['method' => 'server/discover', 'params' => self::stateless()], self::READER, '2026-07-28');
        self::assertSame(200, $status);
        self::assertContains('2026-07-28', $discovered['result']['supportedVersions'] ?? []);
        self::assertContains('2025-11-25', $discovered['result']['supportedVersions'] ?? []);
        self::assertSame('complete', $discovered['result']['resultType'] ?? null);

        [, $listed] = self::post(['method' => 'tools/list', 'params' => self::stateless()], self::READER, '2026-07-28');
        self::assertSame('complete', $listed['result']['resultType'] ?? null);
        self::assertArrayHasKey('ttlMs', $listed['result']);
        self::assertSame('notes_search_tools', $listed['result']['tools'][0]['name'] ?? null);

        $call = ['name' => 'notes_search_tools', 'arguments' => ['action' => 'add', 'object' => 'note']];
        [, $found] = self::post(['method' => 'tools/call', 'params' => $call + self::stateless()], self::READER, '2026-07-28');
        self::assertSame('complete', $found['result']['resultType'] ?? null);
        self::assertSame('notes_add', $found['result']['structuredContent']['tools'][0]['name'] ?? null);
    }

    public function testAStatelessRequestMustNameARevisionTheServerSpeaksAndTheHeaderRepeats(): void
    {
        [$status, $mismatch] = self::post(['method' => 'tools/list', 'params' => self::stateless()], self::READER, '2025-11-25');
        self::assertSame(400, $status);
        self::assertSame(-32600, $mismatch['error']['code'] ?? null);

        [$status, $unknown] = self::post(['method' => 'tools/list', 'params' => self::stateless('2099-01-01')], self::READER, '2099-01-01');
        self::assertSame(400, $status);
        self::assertSame(-32022, $unknown['error']['code'] ?? null);
        self::assertSame('2099-01-01', $unknown['error']['data']['requested'] ?? null);
        self::assertContains('2026-07-28', $unknown['error']['data']['supported'] ?? []);

        // A revision with a handshake is not named in _meta.
        self::assertSame(-32022, self::post(['method' => 'tools/list', 'params' => self::stateless('2025-11-25')], self::READER, '2025-11-25')[1]['error']['code'] ?? null);
        // The stateless revision always carries _meta.
        self::assertSame(400, self::post(['method' => 'tools/list'], self::READER, '2026-07-28')[0]);
        self::assertSame(400, self::post(['method' => 'server/discover'], self::READER, '2026-07-28')[0]);
        // It has no ping.
        self::assertSame(-32601, self::post(['method' => 'ping', 'params' => self::stateless()], self::READER, '2026-07-28')[1]['error']['code'] ?? null);
    }

    public function testANoteAddedByOneRequestIsReadByTheNext(): void
    {
        [, $found] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'add', 'object' => 'note']]], self::READER);
        self::assertSame('notes_add', $found['result']['structuredContent']['tools'][0]['name'] ?? null);

        [, $added] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_add', 'arguments' => ['text' => 'Renew the certificate.']]]], self::READER);
        $id = $added['result']['structuredContent']['id'] ?? null;
        self::assertIsInt($id);

        [, $read] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => $id]]]], self::READER);
        self::assertSame('Renew the certificate.', $read['result']['content'][0]['text'] ?? null);
    }

    public function testTheTokenOfEachRequestDecidesWhatTheGateShows(): void
    {
        $delete = ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_delete', 'arguments' => ['id' => 1]]]];

        [, $reader] = self::post($delete, self::READER);
        self::assertTrue($reader['result']['isError'] ?? null);
        self::assertSame('Unknown tool: notes_delete.', $reader['result']['content'][0]['text'] ?? null);

        [, $admin] = self::post($delete, self::ADMIN);
        self::assertArrayNotHasKey('isError', $admin['result']);
        self::assertSame('Deleted.', $admin['result']['content'][0]['text'] ?? null);
    }

    public function testTheTransportAnswersWhatIsNotAToolCall(): void
    {
        self::assertSame(401, self::post(['method' => 'tools/list'], null)[0]);
        self::assertSame(401, self::post(['method' => 'tools/list'], 'not-a-token')[0]);
        // The caller is asked for before the body is read: a request that
        // names no one learns nothing of how its body would be parsed.
        self::assertSame(401, self::request('POST', 'not json', [])[0]);
        self::assertSame(202, self::post(['method' => 'notifications/initialized', 'notification' => true], self::READER)[0]);
        self::assertSame(-32601, self::post(['method' => 'resources/list'], self::READER)[1]['error']['code'] ?? null);
        self::assertSame(-32602, self::post(['method' => 'tools/call', 'params' => ['arguments' => []]], self::READER)[1]['error']['code'] ?? null);
        self::assertSame(405, self::request('GET', '', ['Authorization: Bearer ' . self::READER])[0]);
        self::assertSame(403, self::request('POST', '{}', ['Authorization: Bearer ' . self::READER, 'Origin: https://elsewhere.example'])[0]);
    }

    /**
     * The params every request of the stateless revision carries.
     *
     * @return array{_meta: array<string, mixed>}
     */
    private static function stateless(string $revision = '2026-07-28'): array
    {
        return ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => $revision,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ]];
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array{int, array<mixed>}
     */
    protected static function post(array $message, ?string $token, ?string $revision = '2025-11-25'): array
    {
        $notification = ($message['notification'] ?? false) === true;
        unset($message['notification']);
        $headers = ['Accept: application/json, text/event-stream'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($revision !== null) {
            $headers[] = 'MCP-Protocol-Version: ' . $revision;
        }

        return self::request('POST', json_encode(['jsonrpc' => '2.0'] + ($notification ? [] : ['id' => 1]) + $message, JSON_THROW_ON_ERROR), $headers);
    }

    /**
     * @param list<string> $headers
     *
     * @return array{int, array<mixed>}
     */
    private static function request(string $method, string $body, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", ['Content-Type: application/json', ...$headers]),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $stream = fopen(self::$url, 'r', false, $context);
        self::assertIsResource($stream);
        // Read from the stream's own metadata: $http_response_header is
        // deprecated as of PHP 8.5, and its replacement only exists from 8.4.
        $meta = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $answer = (string) stream_get_contents($stream);
        fclose($stream);
        $status = is_array($meta) && is_string($meta[0] ?? null) ? (int) (explode(' ', $meta[0])[1] ?? 0) : 0;
        if ($answer === '') {
            return [$status, []];
        }
        $decoded = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return [$status, $decoded];
    }
}
