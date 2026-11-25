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

namespace Novalo\McpToolDiscovery\Tests\Unit;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Exception\InvalidTool;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    public function testAHostToolCalledByItsOwnNameStillWorks(): void
    {
        $gate = new RecordingGate();
        $result = (new Discovery(new CompactMode(), $gate))->callTool('acme_get_file', ['file_id' => '1'], RecordingGate::fixtureTools(), Revision::latest());

        self::assertArrayNotHasKey('isError', $result);
        self::assertSame(['acme_get_file'], array_column($gate->called, 'name'));
    }

    public function testAHiddenToolCalledByItsNameIsUnknown(): void
    {
        $gate = new RecordingGate(['acme_get_file']);
        $hidden = (new Discovery(new CompactMode(), $gate))->call('acme_get_file', [], RecordingGate::fixtureTools());
        $missing = (new Discovery(new CompactMode(), $gate))->call('acme_nope', [], RecordingGate::fixtureTools());

        self::assertTrue($hidden->isError);
        self::assertSame(str_replace('acme_nope', 'acme_get_file', json_encode($missing->content) ?: ''), json_encode($hidden->content));
        self::assertSame([], $gate->called);
    }

    public function testARefusalIsAlwaysAnError(): void
    {
        $result = (new Discovery(new CompactMode(), new RecordingGate([], ['acme_get_file'])))
            ->callTool('acme_get_file', [], RecordingGate::fixtureTools(), Revision::V2025_06_18);

        self::assertTrue($result['isError']);
    }

    public function testAHostToolNamedLikeAModeToolStopsTheHostRatherThanVanish(): void
    {
        $tools = ToolSet::of([
            ['name' => 'get_file', 'description' => 'One file.', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'files', 'description' => 'The host\'s own files tool.', 'inputSchema' => ['type' => 'object']],
        ]);
        $grouped = new GroupedMode(static fn(Tool $tool): string => 'files', ['files' => 'Files']);
        // Hidden from this caller, so the answer cannot depend on who asks.
        $gate = new RecordingGate(['files']);
        $discovery = new Discovery($grouped, $gate);

        foreach ([
            'list' => static fn() => $discovery->listTools($tools, Revision::latest()),
            'call' => static fn() => $discovery->call('get_file', [], $tools),
            'call of the mode' => static fn() => $discovery->callTool('files', ['action' => 'get_file'], $tools, Revision::latest()),
        ] as $what => $request) {
            try {
                $request();
                self::fail($what . ': the clash went unnoticed');
            } catch (InvalidTool $error) {
                self::assertStringContainsString('"files"', $error->getMessage(), $what);
                self::assertStringContainsString('grouped', $error->getMessage(), $what);
            }
        }
        self::assertSame([], $gate->called, 'nothing ran');

        $prefixed = new GroupedMode(static fn(Tool $tool): string => 'files', ['files' => 'Files'], 'acme_');
        self::assertFalse((new Discovery($prefixed, new RecordingGate()))->call('files', [], $tools)->isError, 'a prefix settles it');
    }

    public function testACompactRunnerNameTakenByTheHostIsNamed(): void
    {
        $tools = ToolSet::of([
            ['name' => CompactMode::SEARCH, 'description' => 'The host\'s own search.', 'inputSchema' => ['type' => 'object']],
        ]);

        $this->expectException(InvalidTool::class);
        $this->expectExceptionMessage('Tool "' . CompactMode::SEARCH . '": the compact mode');
        (new Discovery(new CompactMode(), new RecordingGate()))->call(CompactMode::SEARCH, [], $tools);
    }

    public function testTheListIsShapedForTheRevision(): void
    {
        $listed = (new Discovery(new CompactMode(), new RecordingGate()))->listTools(RecordingGate::fixtureTools(), Revision::V2024_11_05);

        self::assertIsArray($listed['tools']);
        foreach ($listed['tools'] as $tool) {
            self::assertIsArray($tool);
            self::assertSame(['name', 'description', 'inputSchema'], array_keys($tool));
        }
    }

    public function testTheGuideHeaderNamesOnlyToolsTheCallerMaySee(): void
    {
        $tools = RecordingGate::fixtureTools();
        $names = ['acme_get_file', 'acme_list_users'];
        $header = (new Discovery(new CompactMode(), new RecordingGate(['acme_list_users'])))->guideHeader($names, $tools);

        self::assertStringContainsString('acme_get_file', $header);
        // Passed the whole list, the header still leaves out what the gate hides.
        self::assertStringNotContainsString('acme_list_users', $header);
    }

    public function testAModeThatListsEveryToolAsItselfHasNoGuideHeader(): void
    {
        self::assertSame('', (new Discovery(new DirectMode(), new RecordingGate()))->guideHeader(['acme_get_file'], RecordingGate::fixtureTools()));
    }

    public function testTheInstructionsAreTheModesAndEmptyWhenItListsEveryTool(): void
    {
        $mode = new CompactMode(prefix: 'acme_');

        self::assertSame($mode->instructions(), (new Discovery($mode, new RecordingGate()))->instructions());
        self::assertSame('', (new Discovery(new DirectMode(), new RecordingGate()))->instructions());
    }
}
