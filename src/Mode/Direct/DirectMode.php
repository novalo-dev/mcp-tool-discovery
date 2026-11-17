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

namespace Novalo\McpToolDiscovery\Mode\Direct;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * Every tool the caller may see, listed as itself, with no tool of its own.
 *
 * For a server small enough to list everything, and for the host that lets
 * its users turn the other modes off. Without it such a host would answer
 * tools/list and tools/call on a path of its own, outside Discovery: the
 * gate's visibility, the shaping per revision and the empty objects would
 * all have to be done again there, and drift from the other modes.
 */
final class DirectMode implements DiscoveryMode
{
    public function name(): string
    {
        return 'direct';
    }

    public function listTools(ToolSet $visible): array
    {
        return $visible->all();
    }

    public function owns(string $name): bool
    {
        // Owning nothing sends every call straight to the runner, which is
        // the path every mode already gives a host tool called by its name.
        return false;
    }

    public function call(string $name, array $arguments, ToolRunner $runner): CallResult
    {
        // Discovery never calls a mode for a name it does not own; a caller
        // that does anyway still meets the runner's checks.
        return $runner->run($name, $arguments);
    }
}
