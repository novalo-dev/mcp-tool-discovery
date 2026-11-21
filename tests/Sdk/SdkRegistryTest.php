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
 * Runs docs/examples/sdk/registry.php, where the tools stay in the SDK's
 * registry and run through its own CallToolHandler, as a client would.
 */
final class SdkRegistryTest extends TestCase
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

    public function testTheModeListsInPlaceOfTheRegistry(): void
    {
        $replies = self::session([self::INITIALIZE, self::INITIALIZED, ['method' => 'tools/list']]);

        self::assertSame(
            ['notes_search_tools', 'notes_run_read_tool', 'notes_run_write_tool', 'notes_run_write_batch'],
            array_column($replies[3]['result']['tools'], 'name'),
        );
    }

    // The definitions come from the registry: the schema the SDK derived from
    // the handler's signature, and the annotations that pick the runner.
    public function testSearchGivesTheSchemaTheSdkDerived(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            self::INITIALIZED,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'read', 'object' => 'note']]],
        ]);

        $found = $replies[3]['result']['structuredContent']['tools'][0];
        self::assertSame('notes_get', $found['name']);
        self::assertSame('notes_run_read_tool', $found['run_with']);
        self::assertSame(['type' => 'integer'], $found['inputSchema']['properties']['id']);
    }

    public function testAToolRunsThroughTheSdkHandler(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            self::INITIALIZED,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_add', 'arguments' => ['text' => 'Renew the certificate.']]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 2]]]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 9]]]],
        ]);

        self::assertSame(['id' => 2], $replies[3]['result']['structuredContent']);
        self::assertSame('Renew the certificate.', $replies[4]['result']['content'][0]['text']);
        // A ToolCallException is the tool's error, as on a direct call.
        self::assertTrue($replies[5]['result']['isError']);
        self::assertSame('No note has this id.', $replies[5]['result']['content'][0]['text']);
    }

    public function testARunFoundBySearchLiftsItsToolForTheSession(): void
    {
        self::assertARunTeachesTheSession(dirname(__DIR__, 2) . '/docs/examples/sdk/registry.php', [self::INITIALIZE, self::INITIALIZED]);
    }

    // The SDK refuses these arguments as a protocol error; through the mode
    // the assistant gets them back as the tool's answer, and can try again.
    public function testArgumentsTheSdkSchemaRefusesComeBackAsAnError(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            self::INITIALIZED,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => 'one']]]],
        ]);

        self::assertArrayNotHasKey('error', $replies[3]);
        self::assertTrue($replies[3]['result']['isError']);
        self::assertStringContainsString('Invalid parameters for tool \'notes_get\'', $replies[3]['result']['content'][0]['text']);
    }

    // The registry still holds notes_delete; a reader reaches it neither
    // through the runner nor by calling it by its own name.
    public function testAToolTheGateHidesIsUnknownEvenByItsOwnName(): void
    {
        $byRunner = ['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_delete', 'arguments' => ['id' => 1]]]];
        $direct = ['method' => 'tools/call', 'params' => ['name' => 'notes_delete', 'arguments' => ['id' => 1]]];
        $list = ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_list']]];

        $reader = self::session([self::INITIALIZE, self::INITIALIZED, $byRunner, $direct, $list]);
        self::assertSame('Unknown tool: notes_delete.', $reader[3]['result']['content'][0]['text']);
        self::assertTrue($reader[4]['result']['isError']);
        self::assertSame(1, $reader[5]['result']['structuredContent']['notes'][0]['id']);

        $admin = self::session([self::INITIALIZE, self::INITIALIZED, $byRunner], 'admin');
        self::assertArrayNotHasKey('isError', $admin[3]['result']);
        self::assertSame('Deleted.', $admin[3]['result']['content'][0]['text']);
    }

    public function testEveryItemOfABatchRunsThroughTheSdkHandler(): void
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

    public function testTheStatelessRevisionReachesTheSdkHandler(): void
    {
        $replies = self::session([
            ['method' => 'tools/list', 'params' => ['_meta' => self::STATELESS]],
            ['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_list'], '_meta' => self::STATELESS]],
        ]);

        self::assertSame('complete', $replies[1]['result']['resultType']);
        self::assertSame('complete', $replies[2]['result']['resultType']);
        self::assertSame(1, $replies[2]['result']['structuredContent']['notes'][0]['id']);
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private static function session(array $messages, string $role = 'reader'): array
    {
        return self::exchange(dirname(__DIR__, 2) . '/docs/examples/sdk/registry.php', $messages, $role);
    }
}
