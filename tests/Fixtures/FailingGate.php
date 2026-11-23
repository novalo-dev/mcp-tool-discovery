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

namespace Novalo\McpToolDiscovery\Tests\Fixtures;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * A gate that shows and allows every tool, and whose tools all fail.
 */
final class FailingGate implements ToolGate
{
    public function visible(Tool $tool): bool
    {
        return true;
    }

    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        return null;
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        return CallResult::error('The tool failed.');
    }
}
