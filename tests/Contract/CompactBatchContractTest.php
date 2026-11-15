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

namespace Novalo\McpToolDiscovery\Tests\Contract;

use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * The compact mode with its batch runner on, every change sent through it as
 * a list of one item: the batch is held to the rules of a single call.
 */
final class CompactBatchContractTest extends ModeContractTestCase
{
    protected function mode(): DiscoveryMode
    {
        return new CompactMode(batchLimit: 5);
    }

    protected function callTo(Tool $tool, ?string $searchId = null): array
    {
        $extra = $searchId === null ? [] : ['search_id' => $searchId];

        return $tool->readsOnly()
            ? [CompactMode::RUN_READ, ['name' => $tool->name, 'arguments' => []] + $extra]
            : [CompactMode::RUN_WRITE_BATCH, ['name' => $tool->name, 'items' => [[]]] + $extra];
    }

    protected function probes(): array
    {
        return [
            [CompactMode::SEARCH, []],
            [CompactMode::SEARCH, ['query' => 'list users staff']],
            [CompactMode::SEARCH, ['action' => 'deactivate', 'object' => 'zzzz']],
            [CompactMode::RUN_WRITE_BATCH, ['name' => self::READ, 'items' => [[]]]],
            [CompactMode::RUN_WRITE_BATCH, ['name' => self::WRITE, 'items' => []]],
        ];
    }
}
