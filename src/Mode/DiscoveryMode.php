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

namespace Novalo\McpToolDiscovery\Mode;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * A way of showing a host's tools to an assistant.
 *
 * Each mode is a pack of its own under src/Mode/<Name>/: a host that uses one
 * mode loads that pack's classes and nothing else. A mode decides what the
 * list looks like and how its own tools route to the host's; it never decides
 * who may do what. It only ever sees the tools the caller may see, and it can
 * only run them through the ToolRunner, which asks the host first.
 *
 * Every mode must pass tests/Contract/ModeContractTestCase.php.
 */
interface DiscoveryMode
{
    /**
     * A short lowercase name, as the host's settings show it: "compact".
     */
    public function name(): string;

    /**
     * The tools the assistant is shown, in the order to show them.
     *
     * May hold the mode's own tools, the host's visible tools, or both - and
     * nothing else: never a tool outside $visible.
     *
     * @return list<Tool>
     */
    public function listTools(ToolSet $visible): array;

    /**
     * Whether this name is one of the mode's own tools.
     */
    public function owns(string $name): bool;

    /**
     * Answers a call to one of the mode's own tools.
     *
     * Only called when owns($name) is true. Reaching a host tool goes through
     * $runner->run(), which checks visibility and the host's authorization.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments, ToolRunner $runner): CallResult;
}
