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
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tests\Fixtures\Groups;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\TestCase;

/**
 * What a host sees when its tools change between two requests, as
 * docs/HOSTS.md tells it: which lists move, so it knows when to send
 * notifications/tools/list_changed, and that searches and runs follow the
 * new set at once, cache and learning store included.
 */
final class ToolsChangeTest extends TestCase
{
    private const READ = [
        'name' => 'acme_get_backup',
        'title' => 'Get a backup',
        'description' => 'One backup of the workspace, with its size and the day it was taken.',
        'inputSchema' => ['type' => 'object', 'properties' => ['backup_id' => ['type' => 'string']], 'required' => ['backup_id']],
        'annotations' => ['readOnlyHint' => true],
    ];

    private const CHANGE = [
        'name' => 'acme_rollback_backup',
        'title' => 'Roll back to a backup',
        'description' => 'Puts the workspace back as it was in one backup.',
        'inputSchema' => ['type' => 'object', 'properties' => ['backup_id' => ['type' => 'string']], 'required' => ['backup_id']],
        'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
    ];

    /**
     * @param array<string, mixed> $added
     */
    private static function moves(DiscoveryMode $mode, ToolSet $before, array $added): bool
    {
        return self::changed($mode, $before, ToolSet::of([...$before->all(), Tool::fromArray($added)]));
    }

    /**
     * Whether the list moves from one set to the other, asked both ways a
     * host can ask it: the two answers compared, and the two fingerprints.
     * They must agree, or a host keeping the fingerprint would tell its
     * client too much or too little.
     */
    private static function changed(DiscoveryMode $mode, ToolSet $before, ToolSet $after): bool
    {
        $discovery = new Discovery($mode, new RecordingGate());
        $list = static fn(ToolSet $tools): string => (string) json_encode($discovery->listTools($tools, Revision::latest()));
        $moved = $list($before) !== $list($after);
        self::assertSame($moved, $discovery->listFingerprint($before, Revision::latest()) !== $discovery->listFingerprint($after, Revision::latest()), 'the fingerprint must move with the list');

        return $moved;
    }

    /**
     * @param array<string, mixed> $changed
     */
    private static function edited(array $changed): ToolSet
    {
        $tools = [];
        foreach (RecordingGate::fixtureTools() as $tool) {
            $tools[] = $tool->name === 'acme_get_file' ? Tool::fromArray([...$tool->toArray(), ...$changed]) : $tool;
        }

        return ToolSet::of($tools);
    }

    public function testTheCompactListMovesOnlyWithAPinnedTool(): void
    {
        $tools = RecordingGate::fixtureTools();

        self::assertFalse(self::moves(new CompactMode(), $tools, self::READ));
        self::assertFalse(self::moves(new CompactMode(), $tools, self::CHANGE));
        self::assertTrue(self::moves(new CompactMode(pinned: ['acme_get_backup']), $tools, self::READ));
    }

    public function testTheGatewayListMovesWithItsFirstChangeOrEveryChangeApart(): void
    {
        $reads = RecordingGate::fixtureTools()->filter(static fn(Tool $tool): bool => $tool->readsOnly());

        self::assertFalse(self::moves(new GatewayMode(), $reads, self::READ));
        self::assertTrue(self::moves(new GatewayMode(), $reads, self::CHANGE), 'the first change marks the gateway');
        self::assertFalse(self::moves(new GatewayMode(), RecordingGate::fixtureTools(), self::CHANGE));
        self::assertTrue(self::moves(new GatewayMode(changesApart: true), RecordingGate::fixtureTools(), self::CHANGE));
    }

    public function testTheGroupedListMovesWithANameOrATitleButNotADescription(): void
    {
        $tools = RecordingGate::fixtureTools();
        $file = ['name' => 'acme_get_folder_size', 'title' => 'Get the size of a folder', 'description' => 'The size of one folder.', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]];

        self::assertTrue(self::moves(Groups::mode(), $tools, $file), 'an action joins its group');
        self::assertTrue(self::moves(Groups::mode(), $tools, self::READ), 'a tool outside the groups is listed as itself');
        self::assertTrue(self::changed(Groups::mode(), $tools, self::edited(['title' => 'Read a file'])));
        self::assertFalse(self::changed(Groups::mode(), $tools, self::edited(['description' => 'Another description.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]])));
    }

    public function testTheDirectListMovesWithEveryTool(): void
    {
        $tools = RecordingGate::fixtureTools();

        self::assertTrue(self::moves(new DirectMode(), $tools, self::READ));
        self::assertTrue(self::changed(new DirectMode(), $tools, self::edited(['description' => 'Another description.'])));
    }

    public function testTheFingerprintIsTheWholeListInTheClientsRevision(): void
    {
        $tools = RecordingGate::fixtureTools();
        $discovery = new Discovery(new DirectMode(), new RecordingGate());
        $now = $discovery->listFingerprint($tools, Revision::V2025_11_25);

        // A page is not the list: the fingerprint is the same whatever page
        // the host answers with, and is built again the same way.
        self::assertNotSame((string) json_encode($discovery->listPage($tools, Revision::V2025_11_25, null, 3)), (string) json_encode($discovery->listTools($tools, Revision::V2025_11_25)));
        self::assertSame($now, $discovery->listFingerprint(ToolSet::of($tools->all()), Revision::V2025_11_25));

        // A tool past the first page still moves it.
        $last = $tools->all()[count($tools) - 1]->name;
        $edited = ToolSet::of(array_map(static fn(Tool $tool): Tool => $tool->name === $last ? Tool::fromArray([...$tool->toArray(), 'description' => 'Another description.']) : $tool, $tools->all()));
        self::assertNotSame($now, $discovery->listFingerprint($edited, Revision::V2025_11_25));

        // A title is a field of its own only from 2025-06-18; before that
        // the annotations carry it, and the oldest revision has neither.
        $retitled = ToolSet::of(array_map(static fn(Tool $tool): Tool => $tool->name === $last ? Tool::fromArray([...$tool->toArray(), 'title' => 'Another title']) : $tool, $tools->all()));
        self::assertNotSame($discovery->listFingerprint($tools, Revision::V2025_11_25), $discovery->listFingerprint($retitled, Revision::V2025_11_25));
        self::assertSame($discovery->listFingerprint($tools, Revision::V2024_11_05), $discovery->listFingerprint($retitled, Revision::V2024_11_05));

        // The gate decides it too: a caller who sees one tool fewer holds another list.
        $hidden = new RecordingGate(hidden: [$last]);
        self::assertNotSame($now, (new Discovery(new DirectMode(), $hidden))->listFingerprint($tools, Revision::V2025_11_25));
    }

    public function testASearchAndARunFollowTheNewToolsAtOnce(): void
    {
        // Shared across requests, as a long-running host keeps them.
        $setup = new SearchSetup(cache: new MemoryIndexCache(), learning: new MemoryLearningStore());
        $before = RecordingGate::fixtureTools();
        $after = ToolSet::of([...$before->all(), Tool::fromArray(self::READ)]);
        $search = static function (ToolSet $tools) use ($setup): array {
            $result = (new Discovery(new CompactMode(), new RecordingGate(), $setup))
                ->call(CompactMode::SEARCH, ['action' => 'get', 'object' => 'backup', 'description' => 'size of a backup'], $tools);
            self::assertIsArray($result->structuredContent);
            self::assertIsArray($result->structuredContent['tools'] ?? null);

            return array_column($result->structuredContent['tools'], 'name');
        };

        self::assertNotContains('acme_get_backup', $search($before));
        $found = $search($after);
        self::assertSame('acme_get_backup', $found[0] ?? null, 'the cached index of the old tools is not the one read');

        // The tool goes again: a run is refused at once, whatever was found
        // or learned before.
        $gate = new RecordingGate();
        $learnt = (new Discovery(new CompactMode(), $gate, $setup))->call(CompactMode::RUN_READ, ['name' => 'acme_get_backup', 'arguments' => ['backup_id' => '1']], $after);
        $gone = (new Discovery(new CompactMode(), $gate, $setup))->call(CompactMode::RUN_READ, ['name' => 'acme_get_backup', 'arguments' => ['backup_id' => '1']], $before);

        self::assertFalse($learnt->isError);
        self::assertTrue($gone->isError);
        self::assertSame(['acme_get_backup'], array_column($gate->called, 'name'));
        self::assertNotContains('acme_get_backup', $search($before));
    }
}
