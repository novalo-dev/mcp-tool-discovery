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
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * The gateway that only reads: changes are listed as themselves and called
 * by their own name, with their own annotations.
 */
final class GatewayChangesApartContractTest extends ModeContractTestCase
{
    protected function mode(): DiscoveryMode
    {
        return new GatewayMode(changesApart: true);
    }

    protected function callTo(Tool $tool, ?string $searchId = null): array
    {
        if (!$tool->readsOnly()) {
            return [$tool->name, []];
        }

        return [
            GatewayMode::NAME,
            ['operation' => GatewayMode::RUN, 'name' => $tool->name, 'arguments' => []] + ($searchId === null ? [] : ['search_id' => $searchId]),
        ];
    }

    protected function probes(): array
    {
        return [
            [GatewayMode::NAME, ['operation' => GatewayMode::SEARCH]],
            [GatewayMode::NAME, ['operation' => GatewayMode::SEARCH, 'query' => 'list users staff']],
            [GatewayMode::NAME, ['operation' => GatewayMode::DESCRIBE, 'names' => [self::READ, self::WRITE]]],
            [GatewayMode::NAME, ['operation' => GatewayMode::RUN, 'name' => self::WRITE]],
        ];
    }
}
