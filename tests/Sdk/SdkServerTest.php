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

use Novalo\McpToolDiscovery\Tests\Examples\StdioSession;
use PHPUnit\Framework\TestCase;

/**
 * Runs docs/examples/sdk/server.php, the notes server on the official MCP PHP
 * SDK, as a client would. It needs the SDK installed in docs/examples/sdk, so
 * it is a test suite of its own, run by its own CI job at the SDK version the
 * example pins.
 */
final class SdkServerTest extends TestCase
{
    use StdioSession;

    private const INITIALIZE = ['method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]];
    private const INITIALIZED = ['method' => 'notifications/initialized', 'notification' => true];
    private const STATELESS = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => [], 'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1']];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/docs/examples/sdk/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/sdk first.');
        }
    }

    public function testTheSdkAnnouncesToolsAndTheLibraryListsThem(): void
    {
        $replies = self::session([self::INITIALIZE, self::INITIALIZED, ['method' => 'tools/list']]);

        self::assertSame('2025-06-18', $replies[1]['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $replies[1]['result']['capabilities']);
        // The handshake tells the model the list is not the whole server.
        self::assertStringStartsWith('Notes kept for the team.', $replies[1]['result']['instructions'] ?? '');
        self::assertStringContainsString('notes_search_tools', $replies[1]['result']['instructions'] ?? '');
        self::assertStringContainsString('notes_run_write_batch', $replies[1]['result']['instructions'] ?? '');
        self::assertSame(
            ['notes_search_tools', 'notes_run_read_tool', 'notes_run_write_tool', 'notes_run_write_batch'],
            array_column($replies[3]['result']['tools'], 'name'),
        );
    }

    public function testAToolFoundBySearchRunsThroughTheWriteRunner(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            self::INITIALIZED,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'add', 'object' => 'note']]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_add', 'arguments' => ['text' => 'Renew the certificate.']]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 2]]]],
        ]);

        self::assertSame('notes_add', $replies[3]['result']['structuredContent']['tools'][0]['name']);
        self::assertSame(['id' => 2], $replies[4]['result']['structuredContent']);
        self::assertSame('Renew the certificate.', $replies[5]['result']['content'][0]['text']);
    }

    public function testARunFoundBySearchLiftsItsToolForTheSession(): void
    {
        self::assertARunTeachesTheSession(dirname(__DIR__, 2) . '/docs/examples/sdk/server.php', [self::INITIALIZE, self::INITIALIZED]);
    }

    public function testAToolTheGateHidesIsUnknownUntilTheRoleAllowsIt(): void
    {
        $delete = ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_delete', 'arguments' => ['id' => 1]]]];

        $reader = self::session([self::INITIALIZE, self::INITIALIZED, $delete]);
        self::assertTrue($reader[3]['result']['isError']);
        self::assertSame('Unknown tool: notes_delete.', $reader[3]['result']['content'][0]['text']);

        $admin = self::session([self::INITIALIZE, self::INITIALIZED, $delete], 'admin');
        self::assertArrayNotHasKey('isError', $admin[3]['result']);
        self::assertSame('Deleted.', $admin[3]['result']['content'][0]['text']);
    }

    // The SDK hands nested objects over as stdClass: without the conversion
    // in the example, every item of a batch was refused as not an object.
    public function testEveryItemOfABatchReachesTheGate(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            self::INITIALIZED,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_batch', 'arguments' => [
                'name' => 'notes_add',
                'items' => [['text' => 'First.'], ['text' => str_repeat('x', 501)], ['text' => 'Third.']],
            ]]],
        ]);

        $batch = $replies[3]['result']['structuredContent'];
        self::assertSame(2, $batch['succeeded']);
        self::assertSame(1, $batch['failed']);
    }

    // The SDK reads the cursor into its request and the example hands it to
    // listPage(). This one names notes_add, which the compact mode never
    // lists, so it is refused as a cursor the list did not give.
    public function testACursorTheListDidNotGiveIsInvalidParams(): void
    {
        $replies = self::session([self::INITIALIZE, self::INITIALIZED, ['method' => 'tools/list'], ['method' => 'tools/list', 'params' => ['cursor' => 'bm90ZXNfYWRk']]]);

        // The four tools of the mode fit one page of the SDK's default size.
        self::assertCount(4, $replies[3]['result']['tools']);
        self::assertArrayNotHasKey('nextCursor', $replies[3]['result']);
        self::assertArrayNotHasKey('result', $replies[4]);
        self::assertSame(-32602, $replies[4]['error']['code']);
        self::assertSame('Invalid cursor: start the list again without one.', $replies[4]['error']['message']);
    }

    public function testTheStatelessRevisionIsServedWithoutAHandshake(): void
    {
        $replies = self::session([
            ['method' => 'server/discover', 'params' => ['_meta' => self::STATELESS]],
            ['method' => 'tools/list', 'params' => ['_meta' => self::STATELESS]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_list'], '_meta' => self::STATELESS]],
        ]);

        self::assertContains('2026-07-28', $replies[1]['result']['supportedVersions']);
        self::assertStringContainsString('notes_search_tools', $replies[1]['result']['instructions'] ?? '');
        self::assertSame('complete', $replies[2]['result']['resultType']);
        self::assertSame('notes_search_tools', $replies[2]['result']['tools'][0]['name']);
        self::assertSame('complete', $replies[3]['result']['resultType']);
        self::assertSame(1, $replies[3]['result']['structuredContent']['notes'][0]['id']);
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private static function session(array $messages, string $role = 'reader'): array
    {
        return self::exchange(dirname(__DIR__, 2) . '/docs/examples/sdk/server.php', $messages, $role);
    }
}
