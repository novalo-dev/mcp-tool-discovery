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

final class CompactModeContractTest extends ModeContractTestCase
{
    protected function mode(): DiscoveryMode
    {
        return new CompactMode();
    }

    protected function callTo(Tool $tool): array
    {
        return [
            $tool->readsOnly() ? CompactMode::RUN_READ : CompactMode::RUN_WRITE,
            ['name' => $tool->name, 'arguments' => []],
        ];
    }

    protected function probes(): array
    {
        return [
            [CompactMode::SEARCH, []],
            [CompactMode::SEARCH, ['query' => 'list users staff']],
            [CompactMode::SEARCH, ['action' => 'list', 'object' => 'users', 'description' => 'every member of the staff']],
            [CompactMode::SEARCH, ['action' => 'deactivate', 'object' => 'zzzz']],
            [CompactMode::SEARCH, ['names' => [self::READ]]],
        ];
    }
}
