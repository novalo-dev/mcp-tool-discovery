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
        $result = $this->toolSearch()->find($query, $limit);
        $this->searchSetup->log?->searched($query, $result);

        return $result;
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
     * Whether a search id given back to run() is of use at all: the search
     * learns from it, or the host's log ties the run to its search. A mode
     * hands one out when this is true.
     */
    public function followsSearches(): bool
    {
        return $this->learns() || $this->searchSetup->log !== null;
    }

    /**
     * @param array<mixed> $arguments named arguments; a list is refused
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
        if (!self::isObject($arguments)) {
            // Checked here as well as in Discovery and in each mode, so a mode
            // written outside the library cannot hand a gate a list either:
            // no gate should have to guess what one means.
            return CallResult::error('arguments must be an object.');
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
        $this->searchSetup->log?->ran($tool, !$result->isError, self::searchIdOf($searchId));

        return $result;
    }

    /**
     * A search id as a search writes it, or null.
     *
     * The client sends it back, so it may be anything: a log is handed only
     * the characters a search writes, and no longer than the search reads of
     * one, so a host can store it as it comes.
     */
    private static function searchIdOf(?string $searchId): ?string
    {
        return $searchId !== null && preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $searchId) === 1 ? $searchId : null;
    }

    private function toolSearch(): ToolSearch
    {
        // Once per request: a search and the run after it share one index.
        return $this->search ??= $this->searchSetup->searchFor($this->visible);
    }

    /**
     * Whether a value is the arguments of a tool: what json_decode(..., true)
     * makes of a JSON object.
     *
     * A JSON array decodes to an array too, and a host that only checks
     * is_array() would hand a gate `[0 => ...]` where it expects named
     * arguments. The empty array stands for `{}`, since both decode to it.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
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
