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
use Novalo\McpToolDiscovery\Exception\InvalidCursor;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;
use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\TestCase;

final class ListPageTest extends TestCase
{
    public function testThePagesPutTogetherAreTheWholeListInEveryModeAndRevision(): void
    {
        $tools = RecordingGate::fixtureTools();
        // Grouped by the last word of the name, so the mode lists many groups.
        $groupOf = static fn(Tool $tool): string => substr((string) strrchr($tool->name, '_'), 1);
        $groups = [];
        foreach ($tools as $tool) {
            $groups[$groupOf($tool)] = ucfirst($groupOf($tool));
        }
        foreach ([new DirectMode(), new CompactMode(), new GroupedMode($groupOf, $groups), new GatewayMode()] as $mode) {
            foreach (Revision::cases() as $revision) {
                $discovery = new Discovery($mode, new RecordingGate(['acme_list_users']));
                $whole = $discovery->listTools($tools, $revision, 60000);

                $listed = [];
                $cursor = null;
                $pages = 0;
                do {
                    $page = $discovery->listPage($tools, $revision, $cursor, 7, 60000);
                    self::assertLessThanOrEqual(7, count($page['tools']));
                    $listed = array_merge($listed, $page['tools']);
                    $cursor = $page['nextCursor'] ?? null;
                    unset($page['tools'], $page['nextCursor']);
                    unset($whole['tools']);
                    // Every page carries the same caching fields as the whole list.
                    self::assertSame($whole, $page, $mode->name() . ' ' . $revision->value);
                    self::assertLessThan(100, ++$pages);
                } while ($cursor !== null);

                self::assertSame(json_encode($discovery->listTools($tools, $revision, 60000)['tools']), json_encode($listed), $mode->name() . ' ' . $revision->value);
            }
        }
    }

    public function testAFullLastPageHasNoNextCursor(): void
    {
        $tools = ToolSet::of(array_slice(RecordingGate::fixtureTools()->all(), 0, 6));
        $discovery = new Discovery(new DirectMode(), new RecordingGate());

        $first = $discovery->listPage($tools, Revision::V2025_11_25, null, 3);
        self::assertIsString($first['nextCursor']);
        $second = $discovery->listPage($tools, Revision::V2025_11_25, $first['nextCursor'], 3);
        self::assertCount(3, $second['tools']);
        self::assertArrayNotHasKey('nextCursor', $second);
        self::assertArrayNotHasKey('nextCursor', $discovery->listPage($tools, Revision::V2025_11_25, null, 6));
    }

    public function testAToolAddedOrRemovedBeforeTheCursorNeitherSkipsNorRepeatsOne(): void
    {
        $all = RecordingGate::fixtureTools()->all();
        $discovery = new Discovery(new DirectMode(), new RecordingGate());
        $first = $discovery->listPage(ToolSet::of(array_slice($all, 1, 10)), Revision::V2025_11_25, null, 5);
        $cursor = $first['nextCursor'];
        self::assertIsString($cursor);

        $added = $discovery->listPage(ToolSet::of(array_slice($all, 0, 11)), Revision::V2025_11_25, $cursor, 5);
        self::assertSame(self::names(array_slice($all, 6, 5)), array_column($added['tools'], 'name'));

        $removed = $discovery->listPage(ToolSet::of(array_slice($all, 2, 9)), Revision::V2025_11_25, $cursor, 5);
        self::assertSame(self::names(array_slice($all, 6, 5)), array_column($removed['tools'], 'name'));
    }

    public function testACursorOfAHiddenToolIsRefusedAsOneThatNamesNothing(): void
    {
        $tools = RecordingGate::fixtureTools();
        $hidden = $tools->all()[2];
        $open = new Discovery(new DirectMode(), new RecordingGate());
        $cursor = $open->listPage($tools, Revision::V2025_11_25, null, 3)['nextCursor'];
        self::assertIsString($cursor);

        $messages = [];
        foreach ([$cursor, 'not-a-cursor', ''] as $given) {
            try {
                (new Discovery(new DirectMode(), new RecordingGate([$hidden->name])))->listPage($tools, Revision::V2025_11_25, $given, 3);
                self::fail('A cursor naming no listed tool was accepted.');
            } catch (InvalidCursor $error) {
                $messages[] = $error->getMessage();
            }
        }
        self::assertCount(1, array_unique($messages));
    }

    public function testAPageHoldsAtLeastOneTool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Discovery(new DirectMode(), new RecordingGate()))->listPage(RecordingGate::fixtureTools(), Revision::V2025_11_25, null, 0);
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, $tools);
    }
}
