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

use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\SearchResult;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Search\ToolSearch;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * The only way a mode reaches the host's tools, and their search.
 *
 * A mode is handed this runner, never the gate itself, so the rules every
 * mode must keep are kept by construction rather than by review: a tool
 * outside the visible set cannot be run or found, nothing runs without the
 * host's authorize() first, and the search learns only from a run that went
 * through both and succeeded.
 */
final class ToolRunner
{
    private ?ToolSearch $search = null;

    public function __construct(
        private readonly ToolSet $visible,
        private readonly ToolGate $gate,
        private readonly SearchSetup $searchSetup = new SearchSetup(),
    ) {}

    /**
     * The tools the caller may see, the only ones a mode may list or search.
     */
    public function visible(): ToolSet
    {
        return $this->visible;
    }

    /**
     * Searches the visible tools, with the host's settings, cache and store.
     */
    public function search(SearchQuery $query, ?int $limit = null): SearchResult
    {
        return $this->toolSearch()->find($query, $limit);
    }

    /**
     * The words of the visible tools' titles, for a caller whose words matched nothing.
     *
     * @return list<string>
     */
    public function vocabulary(int $size = 30): array
    {
        return $this->toolSearch()->vocabulary($size);
    }

    /**
     * Whether a search id given back to run() teaches the search anything,
     * so a mode only hands one out when it is of use.
     */
    public function learns(): bool
    {
        return $this->searchSetup->learns();
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string|null $searchId the id of the search that found the tool, if the caller kept it
     */
    public function run(string $name, array $arguments, ?string $searchId = null): CallResult
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

        $result = $this->gate->call($tool, $arguments);
        // Learned here, for every mode: only a visible tool, authorized, that
        // ran and succeeded counts.
        if ($searchId !== null && $searchId !== '' && !$result->isError && $this->learns()) {
            $this->toolSearch()->learn($searchId, $tool->name);
        }

        return $result;
    }

    private function toolSearch(): ToolSearch
    {
        // Once per request: a search and the run after it share one index.
        return $this->search ??= $this->searchSetup->searchFor($this->visible);
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
