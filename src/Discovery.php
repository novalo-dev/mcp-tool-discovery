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

namespace Novalo\McpToolDiscovery;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Protocol\Shaper;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * What a host calls from its `tools/list` and `tools/call` handlers.
 *
 * One instance per request: it holds the gate of the current caller. The
 * visible set is computed here, once, before the mode sees anything, so no
 * mode can list, search or run a tool the gate hides.
 */
final class Discovery
{
    /**
     * @param SearchSetup $search the search settings, and the optional cache and
     *        learning store, for whichever mode is used
     */
    public function __construct(
        private readonly DiscoveryMode $mode,
        private readonly ToolGate $gate,
        private readonly SearchSetup $search = new SearchSetup(),
    ) {}

    /**
     * The `result` member of the `tools/list` answer.
     *
     * @param 'private'|'public' $cacheScope
     *
     * @return array<string, mixed>
     */
    public function listTools(ToolSet $tools, Revision $revision, int $ttlMs = 0, string $cacheScope = 'private'): array
    {
        return Shaper::listResult($this->mode->listTools($this->visible($tools)), $revision, $ttlMs, $cacheScope);
    }

    /**
     * The `result` member of the `tools/call` answer.
     *
     * A host tool called by its own name is still accepted in every mode: an
     * assistant holding a list from before the host switched modes keeps
     * working, and the call meets the same checks as through the mode.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments, ToolSet $tools, Revision $revision): array
    {
        return Shaper::callResult($this->call($name, $arguments, $tools), $revision);
    }

    /**
     * The same call, before it is shaped, for hosts that build their own answer.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments, ToolSet $tools): CallResult
    {
        $runner = new ToolRunner($this->visible($tools), $this->gate, $this->search);

        return $this->mode->owns($name) ? $this->mode->call($name, $arguments, $runner) : $runner->run($name, $arguments);
    }

    private function visible(ToolSet $tools): ToolSet
    {
        // A host tool under the name of one of the mode's own tools would be
        // unreachable and ambiguous: it is left out rather than shadowed.
        return $tools->filter(fn($tool): bool => !$this->mode->owns($tool->name) && $this->gate->visible($tool));
    }
}
