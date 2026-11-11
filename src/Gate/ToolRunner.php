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

namespace Novalo\McpToolDiscovery\Gate;

use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * The only way a mode reaches the host's tools.
 *
 * A mode is handed this runner, never the gate itself, so the two rules every
 * mode must keep are kept by construction rather than by review: a tool
 * outside the visible set cannot be run, and nothing runs without the host's
 * authorize() first.
 */
final class ToolRunner
{
    public function __construct(
        private readonly ToolSet $visible,
        private readonly ToolGate $gate,
    ) {}

    /**
     * The tools the caller may see, the only ones a mode may list or search.
     */
    public function visible(): ToolSet
    {
        return $this->visible;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function run(string $name, array $arguments): CallResult
    {
        $tool = $this->visible->get($name);
        if ($tool === null) {
            // The same answer for a tool that does not exist and one the
            // caller may not see, so the error never reveals the second.
            return CallResult::error(sprintf('Unknown tool: %s.', self::printable($name)));
        }
        $refusal = $this->gate->authorize($tool, $arguments);
        if ($refusal !== null) {
            return $refusal->isError ? $refusal : new CallResult($refusal->content, true, $refusal->structuredContent);
        }

        return $this->gate->call($tool, $arguments);
    }

    /**
     * A name echoed back to the assistant, cut short and stripped of anything
     * but the characters a tool name may hold.
     */
    public static function printable(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '?', substr($name, 0, 128));

        return is_string($clean) ? $clean : '?';
    }
}
