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

namespace Novalo\McpToolDiscovery\Tests\Logiscape;

use Novalo\McpToolDiscovery\Tests\Examples\LocalServer;
use PHPUnit\Framework\TestCase;

/**
 * Runs docs/examples/logiscape/http-server.php, the notes server on
 * logiscape/mcp-sdk-php over the SDK's HTTP runner, behind PHP's built-in
 * web server: one process per request, as PHP-FPM would run it. The SDK
 * keeps the session in files between requests; the library is given the
 * caller of each.
 */
final class LogiscapeHttpServerTest extends TestCase
{
    private const READER = 'reader-token';
    private const ADMIN = 'admin-token';
    private const STATELESS = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
        'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
    ];

    private static ?LocalServer $server = null;
    private static string $url = '';
    private static string $data = '';

    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/docs/examples/logiscape/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/logiscape first.');
        }
        self::$data = sys_get_temp_dir() . '/mcp-logiscape-http-example-' . bin2hex(random_bytes(6));
        self::$server = LocalServer::start(
            static fn(string $address): array => [PHP_BINARY, '-S', $address, dirname(__DIR__, 2) . '/docs/examples/logiscape/http-server.php'],
            ['MCP_NOTES_DATA' => self::$data],
        );
        self::$url = self::$server->url();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        LocalServer::remove(self::$data);
    }

    public function testTheSessionKeepsTheRevisionTheClientAgreedOn(): void
    {
        $session = self::open(self::READER);
        [$status, $listed] = self::post(['method' => 'tools/list'], self::READER, $session);

        self::assertSame(200, $status);
        self::assertSame(
            ['notes_search_tools', 'notes_run_read_tool', 'notes_run_write_tool', 'notes_run_write_batch'],
            array_column($listed['result']['tools'], 'name'),
        );
        // 2025-06-18 knows titles: the answer was shaped for the session's revision.
        self::assertArrayHasKey('title', $listed['result']['tools'][0]);
        self::assertArrayNotHasKey('resultType', $listed['result']);
    }

    // The instructions are set on the JSON the runner built: the rest of the
    // answer must come out as the SDK wrote it, empty objects included.
    public function testTheInstructionsLeaveTheRestOfTheHandshakeAsItWas(): void
    {
        [, $body] = self::post(['method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]], self::READER, null, [], false);

        self::assertSame('{}', json_encode($body['result']['capabilities']['experimental'] ?? null));
        self::assertSame(['listChanged' => false], (array) ($body['result']['capabilities']['tools'] ?? null));
        self::assertSame('notes', $body['result']['serverInfo']['name'] ?? null);
    }

    public function testAToolFoundBySearchRunsInALaterRequest(): void
    {
        $session = self::open(self::READER);

        [, $found] = self::call('notes_search_tools', ['action' => 'add', 'object' => 'note'], self::READER, $session);
        self::assertSame('notes_add', $found['result']['structuredContent']['tools'][0]['name'] ?? null);

        [, $added] = self::call('notes_run_write_tool', ['name' => 'notes_add', 'arguments' => ['text' => 'Renew the certificate.']], self::READER, $session);
        $id = $added['result']['structuredContent']['id'] ?? null;
        self::assertIsInt($id);

        [, $read] = self::call('notes_run_read_tool', ['name' => 'notes_get', 'arguments' => ['id' => $id]], self::READER, $session);
        self::assertSame('Renew the certificate.', $read['result']['content'][0]['text'] ?? null);
    }

    public function testTheTokenOfEachRequestDecidesWhatTheGateShows(): void
    {
        $delete = ['name' => 'notes_delete', 'arguments' => ['id' => 1]];

        [, $reader] = self::call('notes_run_write_tool', $delete, self::READER, self::open(self::READER));
        self::assertTrue($reader['result']['isError'] ?? null);
        self::assertSame('Unknown tool: notes_delete.', $reader['result']['content'][0]['text'] ?? null);

        [, $admin] = self::call('notes_run_write_tool', $delete, self::ADMIN, self::open(self::ADMIN));
        self::assertArrayNotHasKey('isError', $admin['result']);
        self::assertSame('Deleted.', $admin['result']['content'][0]['text'] ?? null);
    }

    // The SDK binds a session to no caller unless its OAuth is on, so a
    // reader may send an admin's session id. The session holds the revision,
    // never a right: the gate is built from the token of the request.
    public function testASessionOfAnotherCallerGivesNoneOfItsRights(): void
    {
        [, $answer] = self::call('notes_run_write_tool', ['name' => 'notes_delete', 'arguments' => ['id' => 1]], self::READER, self::open(self::ADMIN));

        self::assertTrue($answer['result']['isError'] ?? null);
        self::assertSame('Unknown tool: notes_delete.', $answer['result']['content'][0]['text'] ?? null);
    }

    public function testARequestThatNamesNoCallerIsRefusedBeforeTheSdk(): void
    {
        self::assertSame(401, self::post(['method' => 'tools/list'], null, null)[0]);
        self::assertSame(401, self::post(['method' => 'tools/list'], 'not-a-token', null)[0]);
    }

    public function testTheStatelessRevisionNeedsNoSession(): void
    {
        $headers = ['MCP-Protocol-Version: 2026-07-28'];
        [$status, $discovered] = self::post(['method' => 'server/discover', 'params' => ['_meta' => self::STATELESS]], self::READER, null, [...$headers, 'Mcp-Method: server/discover']);
        self::assertSame(200, $status);
        self::assertStringContainsString('notes_search_tools', $discovered['result']['instructions'] ?? '');

        [$status, $listed] = self::post(['method' => 'tools/list', 'params' => ['_meta' => self::STATELESS]], self::READER, null, [...$headers, 'Mcp-Method: tools/list']);
        self::assertSame(200, $status);
        self::assertSame('complete', $listed['result']['resultType'] ?? null);
        self::assertArrayHasKey('ttlMs', $listed['result']);
        self::assertSame('notes_search_tools', $listed['result']['tools'][0]['name'] ?? null);
    }

    // Nothing in memory survives a request here: the index a search built
    // must be on disk for the next request to read instead of building it.
    public function testTheSearchIndexIsKeptOnDiskBetweenRequests(): void
    {
        self::call('notes_search_tools', ['query' => 'note'], self::READER, self::open(self::READER));

        self::assertNotSame([], glob(self::$data . '/index/*.php') ?: []);
    }

    /**
     * Opens a session for this token, as a client does before any other
     * request of a revision with a handshake.
     */
    private static function open(string $token): string
    {
        [$status, $body, $session] = self::post(['method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]], $token, null);
        self::assertSame(200, $status);
        self::assertSame('2025-06-18', $body['result']['protocolVersion'] ?? null);
        self::assertStringContainsString('notes_search_tools', $body['result']['instructions'] ?? '');
        self::assertIsString($session);
        self::assertSame(202, self::post(['method' => 'notifications/initialized', 'notification' => true], $token, $session)[0]);

        return $session;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{int, array<mixed>, ?string}
     */
    private static function call(string $name, array $arguments, string $token, string $session): array
    {
        return self::post(['method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], $token, $session);
    }

    /**
     * @param array<string, mixed> $message
     * @param list<string>         $headers
     * @param bool                 $assoc   false keeps empty objects of the answer as objects
     *
     * @return array{int, array<mixed>, ?string} the status, the decoded body and the session the answer names
     */
    private static function post(array $message, ?string $token, ?string $session, array $headers = [], bool $assoc = true): array
    {
        $notification = ($message['notification'] ?? false) === true;
        unset($message['notification']);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json, text/event-stream';
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($session !== null) {
            $headers[] = 'Mcp-Session-Id: ' . $session;
            $headers[] = 'MCP-Protocol-Version: 2025-06-18';
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode(['jsonrpc' => '2.0'] + ($notification ? [] : ['id' => 1]) + $message, JSON_THROW_ON_ERROR),
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
        $meta = is_array($meta) ? array_filter($meta, is_string(...)) : [];
        $status = (int) (explode(' ', (string) reset($meta))[1] ?? 0);
        $named = null;
        foreach ($meta as $line) {
            if (preg_match('/^Mcp-Session-Id:\s*(\S+)/i', $line, $match) === 1) {
                $named = $match[1];
            }
        }
        $decoded = $answer === '' ? [] : json_decode($answer, $assoc, 512, JSON_THROW_ON_ERROR);
        // Decoded as objects, the answer is turned into arrays one level at a
        // time, so an empty object of the wire stays a stdClass to look at.
        $decoded = $decoded instanceof \stdClass ? self::levels($decoded) : $decoded;
        self::assertIsArray($decoded);

        return [$status, $decoded, $named];
    }

    /**
     * An answer decoded as objects, as arrays down to its empty objects.
     *
     * @return array<mixed>
     */
    private static function levels(\stdClass $object): array
    {
        $levels = [];
        foreach (get_object_vars($object) as $key => $value) {
            $levels[$key] = $value instanceof \stdClass && get_object_vars($value) !== [] ? self::levels($value) : $value;
        }

        return $levels;
    }
}
