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

use Novalo\McpToolDiscovery\Tests\Examples\StdioSession;
use PHPUnit\Framework\TestCase;

/**
 * Runs docs/examples/logiscape/server.php, the notes server on
 * logiscape/mcp-sdk-php, as a client would. It needs the SDK installed in
 * docs/examples/logiscape, so it is a test suite of its own, run by its own
 * CI job at the SDK version the example pins.
 */
final class LogiscapeServerTest extends TestCase
{
    use StdioSession;

    private const INITIALIZE = ['method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]];
    private const INITIALIZED = ['method' => 'notifications/initialized', 'notification' => true];
    private const STATELESS = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => [], 'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1']];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/docs/examples/logiscape/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/logiscape first.');
        }
    }

    public function testTheSdkAnnouncesToolsOnlyAndTheLibraryListsThem(): void
    {
        $replies = self::session([self::INITIALIZE, self::INITIALIZED, ['method' => 'tools/list']]);

        self::assertSame('2025-06-18', $replies[1]['result']['protocolVersion']);
        // The SDK's Server announces what has a handler: tools, and not the
        // prompts and resources its McpServer wrapper would have added.
        self::assertSame(['listChanged' => false], $replies[1]['result']['capabilities']['tools']);
        self::assertArrayNotHasKey('prompts', $replies[1]['result']['capabilities']);
        self::assertArrayNotHasKey('resources', $replies[1]['result']['capabilities']);
        self::assertSame(
            ['notes_search_tools', 'notes_run_read_tool', 'notes_run_write_tool', 'notes_run_write_batch'],
            array_column($replies[3]['result']['tools'], 'name'),
        );
    }

    // The SDK has no setting for them: the session of the example adds them.
    public function testTheHandshakeCarriesTheInstructions(): void
    {
        $replies = self::session([self::INITIALIZE]);

        self::assertStringStartsWith('Notes kept for the team.', $replies[1]['result']['instructions'] ?? '');
        self::assertStringContainsString('notes_search_tools', $replies[1]['result']['instructions'] ?? '');
        self::assertStringContainsString('notes_run_write_batch', $replies[1]['result']['instructions'] ?? '');
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
        self::assertARunTeachesTheSession(dirname(__DIR__, 2) . '/docs/examples/logiscape/server.php', [self::INITIALIZE, self::INITIALIZED]);
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

    // This cursor names notes_add, which the compact mode never lists, so it
    // is refused as a cursor the list did not give, with the code of invalid
    // params instead of the internal error the SDK sends for an exception.
    public function testACursorTheListDidNotGiveIsInvalidParams(): void
    {
        $replies = self::session([self::INITIALIZE, self::INITIALIZED, ['method' => 'tools/list', 'params' => ['cursor' => 'bm90ZXNfYWRk']]]);

        self::assertArrayNotHasKey('result', $replies[3]);
        self::assertSame(-32602, $replies[3]['error']['code']);
        self::assertSame('Invalid cursor: start the list again without one.', $replies[3]['error']['message']);
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

    // The library shapes for the revision agreed: no resultType for a client
    // of an older one, which the SDK removes too.
    public function testAnOlderRevisionGetsNoResultType(): void
    {
        $replies = self::session([self::INITIALIZE, self::INITIALIZED, ['method' => 'tools/list']]);

        self::assertArrayNotHasKey('resultType', $replies[3]['result']);
        self::assertArrayNotHasKey('ttlMs', $replies[3]['result']);
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private static function session(array $messages, string $role = 'reader'): array
    {
        return self::exchange(dirname(__DIR__, 2) . '/docs/examples/logiscape/server.php', $messages, $role);
    }
}
