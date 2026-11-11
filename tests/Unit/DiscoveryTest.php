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
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
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

    public function testAHostToolNamedLikeAModeToolIsLeftOut(): void
    {
        $tools = ToolSet::of([
            ['name' => CompactMode::SEARCH, 'description' => 'The host\'s own search.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'get_file', 'description' => 'One file.', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
        ]);
        $gate = new RecordingGate();
        $result = (new Discovery(new CompactMode(), $gate))->call(CompactMode::SEARCH, [], $tools);

        self::assertFalse($result->isError);
        self::assertSame(['get_file'], array_column($result->structuredContent['tools'] ?? [], 'name'));
        self::assertSame([], $gate->called, 'the host tool was not run instead of the search');
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
}
