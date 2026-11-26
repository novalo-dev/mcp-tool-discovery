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

use Novalo\McpToolDiscovery\Tests\Examples\SessionHttpServerTestCase;

/**
 * Runs docs/examples/logiscape/http-server.php, the notes server on
 * logiscape/mcp-sdk-php over the SDK's HTTP runner, behind PHP's built-in
 * web server: one process per request, as PHP-FPM would run it. The SDK
 * keeps the session in files between requests; the library is given the
 * caller of each.
 *
 * What every such server answers is in SessionHttpServerTestCase; what
 * follows is where this SDK differs.
 */
final class LogiscapeHttpServerTest extends SessionHttpServerTestCase
{
    protected static function example(): string
    {
        return 'logiscape';
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

    // The SDK binds a session to no caller unless its OAuth is on, so a
    // reader may send an admin's session id. The session holds the revision,
    // never a right: the gate is built from the token of the request.
    public function testASessionOfAnotherCallerGivesNoneOfItsRights(): void
    {
        [, $answer] = self::call('notes_run_write_tool', ['name' => 'notes_delete', 'arguments' => ['id' => 1]], self::READER, self::open(self::ADMIN));

        self::assertTrue($answer['result']['isError'] ?? null);
        self::assertSame('Unknown tool: notes_delete.', $answer['result']['content'][0]['text'] ?? null);
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
}
