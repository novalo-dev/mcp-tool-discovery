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

use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;
use Novalo\McpToolDiscovery\Tests\Fixtures\Groups;
use Novalo\McpToolDiscovery\Tool\Tool;

final class GroupedModeContractTest extends ModeContractTestCase
{
    protected function mode(): DiscoveryMode
    {
        return Groups::mode();
    }

    protected function callTo(Tool $tool, ?string $searchId = null): array
    {
        $group = Groups::of($tool);
        if (!isset(Groups::TITLES[$group])) {
            return [$tool->name, []];
        }

        return [
            $group . ($tool->readsOnly() ? '' : GroupedMode::CHANGE_SUFFIX),
            ['action' => $tool->name, 'arguments' => []],
        ];
    }

    protected function probes(): array
    {
        return [
            ['users', []],
            ['users', ['describe' => [self::READ, self::REFUSED, 'acme_get_user']]],
            ['files', ['describe' => [self::READ]]],
            ['users_change', []],
            ['tickets', ['action' => self::WRITE]],
        ];
    }

    protected function searches(): bool
    {
        return false;
    }
}
