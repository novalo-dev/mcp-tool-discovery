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

/**
 * Runs docs/examples/sdk/registry.php, where the tools are added to the SDK's
 * registry with addTool().
 */
final class SdkRegistryTest extends RegistryServerTestCase
{
    protected static function script(): string
    {
        return dirname(__DIR__, 2) . '/docs/examples/sdk/registry.php';
    }
}
