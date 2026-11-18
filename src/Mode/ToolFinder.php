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
use Novalo\McpToolDiscovery\Protocol\Json;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * The answer to "which tools may I use for this", shared by the modes that
 * search.
 *
 * Kept in one place so a search through one mode reads exactly like a search
 * through another: the same fields in, the same tools out, the same rules for
 * a name the caller may not see. Each mode only says which of its tools runs
 * a given host tool.
 */
final class ToolFinder
{
    /** @var \Closure(Tool): string */
    private readonly \Closure $runWith;

    /** @var (\Closure(Tool): string)|null */
    private readonly ?\Closure $groupOf;

    /**
     * @param callable(Tool): string $runWith the name of the mode's tool that runs this host tool
     * @param int $maxDescribed the most tools one answer describes in full
     * @param (callable(Tool): string)|null $groupOf the group a tool belongs to, for the list an empty request gives
     * @param string $retry what to tell the caller when nothing matches, after "Nothing matches."
     * @param bool $markChanges mark each tool that changes something with "changes": true, for a mode whose run_with does not tell reads and changes apart
     */
    public function __construct(
        callable $runWith,
        private readonly int $maxDescribed,
        ?callable $groupOf = null,
        private readonly string $retry = 'Say it again with words from vocabulary, or call with no argument for every tool you may use.',
        private readonly bool $markChanges = false,
    ) {
        $this->runWith = \Closure::fromCallable($runWith);
        $this->groupOf = $groupOf === null ? null : \Closure::fromCallable($groupOf);
        if ($maxDescribed < 1) {
            throw new \InvalidArgumentException('A search must be able to describe at least one tool.');
        }
    }

    /**
     * Describes the tools named in `names`, then fills up to the limit with
     * what the search finds for `action`, `object`, `description` and
     * `query`. With none of them, lists every visible tool, names only.
     *
     * @param array<string, mixed> $arguments
     */
    public function find(array $arguments, ToolRunner $runner): CallResult
    {
        $visible = $runner->visible();
        $names = $arguments['names'] ?? null;
        try {
            $query = SearchQuery::fromArguments($arguments);
        } catch (\InvalidArgumentException) {
            $query = null;
        }
        if ($query === null || ($names !== null && !is_array($names))) {
            return CallResult::error('action, object, description and query must be strings, and names a list of tool names.');
        }
        $names = array_values(array_unique(array_filter(
            array_map(static fn($name): string => is_string($name) ? trim($name) : '', $names ?? []),
            static fn(string $name): bool => $name !== '',
        )));

        if ($query->isEmpty() && $names === []) {
            return CallResult::json(['tools' => $this->everyTool($visible)]);
        }

        $found = [];
        $unknown = [];
        foreach (array_slice($names, 0, $this->maxDescribed) as $name) {
            $tool = $visible->get($name);
            if ($tool === null) {
                // One list for every reason, as for a call: a tool the caller
                // may not see is reported exactly like one that does not exist.
                $unknown[] = ToolRunner::printable($name);
            } else {
                $found[$name] = $tool;
            }
        }
        $result = null;
        if (!$query->isEmpty() && count($found) < $this->maxDescribed) {
            $result = $runner->search($query, $this->maxDescribed);
            foreach ($result->tools as $tool) {
                if (count($found) >= $this->maxDescribed) {
                    break;
                }
                $found[$tool->name] = $tool;
            }
        }

        $answer = ['tools' => array_values(array_map(fn(Tool $tool): array => $this->entry($tool) + [
            'description' => $tool->description(),
            'inputSchema' => Json::schema($tool->inputSchema()),
        ], $found))];
        if ($unknown !== []) {
            $answer['unknown'] = $unknown;
        }
        if ($runner->learns() && $result !== null && $result->searchId !== null && $result->tools !== []) {
            $answer['search_id'] = $result->searchId;
        }
        if ($result !== null && ($found === [] || $result->unmatched !== [])) {
            // The words this server uses, computed from its own tools, so the
            // assistant can say again what no tool understood.
            if ($result->unmatched !== []) {
                $answer['unmatched'] = array_map([ToolRunner::class, 'printable'], $result->unmatched);
            }
            $answer['vocabulary'] = $runner->vocabulary();
        }
        if ($found === []) {
            $answer['hint'] = 'Nothing matches. ' . $this->retry;
        }

        return CallResult::json($answer);
    }

    /**
     * The tool's parameters added to an answer that reports a failure, so the
     * assistant can fix its call without searching again.
     */
    public static function withParameters(CallResult $result, Tool $tool): CallResult
    {
        $schema = json_encode($tool->inputSchema(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($schema) ? $result->withContent('Parameters of ' . $tool->name . ': ' . $schema) : $result;
    }

    /**
     * Every visible tool, names only, grouped when the host says how.
     *
     * @return list<array<string, mixed>>
     */
    private function everyTool(ToolSet $visible): array
    {
        $entry = $this->entry(...);
        if ($this->groupOf === null) {
            return array_map($entry, $visible->all());
        }
        // Groups in the order they first appear, so the host's order holds.
        $order = [];
        $members = [];
        foreach ($visible as $tool) {
            $group = self::group(($this->groupOf)($tool), $tool);
            if (!isset($members[$group])) {
                $order[] = $group;
            }
            $members[$group][] = $entry($tool);
        }

        return array_map(static fn(string $group): array => ['group' => $group, 'tools' => $members[$group]], $order);
    }

    /**
     * What names a tool in every answer, short or full.
     *
     * @return array<string, mixed>
     */
    private function entry(Tool $tool): array
    {
        $entry = ['name' => $tool->name, 'title' => $tool->title(), 'run_with' => ($this->runWith)($tool)];
        // Only on changes, so a server that only reads pays nothing: the
        // model is asked to confirm each change with the person, and through
        // a single tool nothing else tells it which tools change something.
        if ($this->markChanges && !$tool->readsOnly()) {
            $entry['changes'] = true;
        }

        return $entry;
    }

    /**
     * The group the host gave, checked: its callable is typed in a docblock
     * only, and a map read with no fallback returns null for the first tool
     * it misses. That tool is named here, not in a TypeError deep in
     * array_map(). The grouped mode checks its own groupOf with it too, so
     * every mode reports a wrong group the same way.
     *
     * @internal
     */
    public static function group(mixed $group, Tool $tool): string
    {
        if (!\is_string($group)) {
            throw new \UnexpectedValueException(sprintf('groupOf must return a string for every tool; it returned %s for "%s".', get_debug_type($group), $tool->name));
        }

        return $group;
    }
}
