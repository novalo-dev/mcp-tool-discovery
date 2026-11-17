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

use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Tool\Tool;

final class DirectModeContractTest extends ModeContractTestCase
{
    protected function mode(): DiscoveryMode
    {
        return new DirectMode();
    }

    protected function callTo(Tool $tool, ?string $searchId = null): array
    {
        // Every tool is called by its own name, and with no search there is
        // no search id to carry.
        return [$tool->name, []];
    }

    protected function probes(): array
    {
        return [];
    }

    protected function searches(): bool
    {
        return false;
    }
}
