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

namespace Novalo\McpToolDiscovery\Mode\Compact;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Protocol\Json;
use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\ToolSearch;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * Three tools instead of the whole list: search, then run.
 *
 * Every tool a server offers weighs on every request of the assistant, and
 * some clients cap how many they accept. In compact mode the assistant sees a
 * search tool and two runners. It searches, reads the schemas of the few
 * tools that match, and runs one through the runner its kind calls for.
 *
 * Two runners rather than one: the runner for changes carries
 * `destructiveHint`, and that flag is the only lever a client offers to ask
 * the person first. A read sent to it, or a change sent to the read runner,
 * is refused, so a change can never slip past that confirmation.
 */
final class CompactMode implements DiscoveryMode
{
    public const SEARCH = 'search_tools';
    public const RUN_READ = 'run_read_tool';
    public const RUN_WRITE = 'run_write_tool';

    private readonly string $searchName;
    private readonly string $readName;
    private readonly string $writeName;

    /** @var (\Closure(Tool): string)|null */
    private readonly ?\Closure $groupOf;

    /**
     * @param string $prefix put before the three names, e.g. "shop_" for shop_search_tools
     * @param list<string> $pinned host tools listed as themselves next to the three, e.g. a ping
     * @param array{search?: string, read?: string, write?: string} $titles the titles shown to people, translated by the host
     * @param (callable(Tool): string)|null $groupOf the group a tool belongs to, for the list a search with no argument gives
     * @param int $maxDescribed the most tools one search describes in full
     * @param bool $schemaOnError add the tool's parameters to a failed run, so the assistant can fix its call
     */
    public function __construct(
        string $prefix = '',
        private readonly array $pinned = [],
        private readonly array $titles = [],
        ?callable $groupOf = null,
        private readonly SearchOptions $searchOptions = new SearchOptions(),
        private readonly int $maxDescribed = 10,
        private readonly bool $schemaOnError = true,
    ) {
        $this->searchName = $prefix . self::SEARCH;
        $this->readName = $prefix . self::RUN_READ;
        $this->writeName = $prefix . self::RUN_WRITE;
        $this->groupOf = $groupOf === null ? null : \Closure::fromCallable($groupOf);
        if ($maxDescribed < 1) {
            throw new \InvalidArgumentException('A search must be able to describe at least one tool.');
        }
    }

    public function name(): string
    {
        return 'compact';
    }

    public function listTools(ToolSet $visible): array
    {
        $tools = [];
        foreach ($this->pinned as $name) {
            $tool = $visible->get($name);
            if ($tool !== null) {
                $tools[] = $tool;
            }
        }

        return [...$tools, ...$this->ownTools()];
    }

    public function owns(string $name): bool
    {
        return $name === $this->searchName || $name === $this->readName || $name === $this->writeName;
    }

    public function call(string $name, array $arguments, ToolRunner $runner): CallResult
    {
        return $name === $this->searchName
            ? $this->search($arguments, $runner->visible())
            : $this->run($name === $this->writeName, $arguments, $runner);
    }

    /**
     * The block a host puts at the top of a guide (an MCP prompt) that names
     * tools: in compact mode those tools are not in the assistant's list, and
     * without it the assistant looks for tools it cannot see. Empty when the
     * guide names none of the visible tools.
     *
     * @param iterable<string> $names the tool names the guide uses
     */
    public function guideHeader(iterable $names, ToolSet $visible): string
    {
        $reads = [];
        $writes = [];
        foreach ($names as $name) {
            $tool = $visible->get($name);
            if ($tool === null || in_array($name, $this->pinned, true) || $this->owns($name)) {
                continue;
            }
            if ($tool->readsOnly()) {
                $reads[] = $name;
            } else {
                $writes[] = $name;
            }
        }
        if ($reads === [] && $writes === []) {
            return '';
        }
        $lines = [
            '## How to call the tools of this guide',
            '',
            'This server lists its tools in compact form: the tools this guide names are not in your tool list. '
                . sprintf('Read the parameters of the ones you need with %s (names), then run them:', $this->searchName),
        ];
        if ($reads !== []) {
            $lines[] = sprintf('- through %s: %s', $this->readName, implode(', ', $reads));
        }
        if ($writes !== []) {
            $lines[] = sprintf('- through %s: %s', $this->writeName, implode(', ', $writes));
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * @return list<Tool>
     */
    private function ownTools(): array
    {
        return [
            Tool::fromArray([
                'name' => $this->searchName,
                'title' => $this->titles['search'] ?? 'Search the tools',
                'description' => sprintf(
                    'Finds the tools you may use on this server and gives their parameters. The server lists its tools in compact form: call this first, then run what you found with %s (reads) or %s (changes), as each result says. Give keywords in English ("order status", "stock"), or the exact names a guide gives. With neither, lists every tool you may use, names only. Reads only.',
                    $this->readName,
                    $this->writeName,
                ),
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => sprintf('Keywords in English, e.g. "refund order". Up to %d tools come back, best match first, each with its inputSchema.', $this->maxDescribed),
                        ],
                        'names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => sprintf('Exact tool names to describe in full, e.g. the ones a guide names. Up to %d.', $this->maxDescribed),
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ]),
            self::runner($this->readName, $this->titles['read'] ?? 'Run a reading tool', 'Runs one tool that only reads, found with ' . $this->searchName . '. A tool that changes something is refused here: run it with ' . $this->writeName . '.', true),
            self::runner($this->writeName, $this->titles['write'] ?? 'Run a tool that changes something', 'Runs one tool that changes something, found with ' . $this->searchName . '. Ask the person before each call, as for any change. A tool that only reads is refused here: run it with ' . $this->readName . '.', false),
        ];
    }

    private static function runner(string $name, string $title, string $description, bool $reads): Tool
    {
        return Tool::fromArray([
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The exact name of the tool, as the search gave it.'],
                    'arguments' => ['type' => 'object', 'description' => 'The arguments of that tool, following its inputSchema.'],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            // The runner for changes is marked destructive on purpose: it is
            // the flag that makes a client ask the person before the call.
            'annotations' => $reads
                ? ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true]
                : ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function search(array $arguments, ToolSet $visible): CallResult
    {
        $query = $arguments['query'] ?? null;
        $names = $arguments['names'] ?? null;
        if (($query !== null && !is_string($query)) || ($names !== null && !is_array($names))) {
            return CallResult::error('query must be a string and names a list of tool names.');
        }
        $names = array_values(array_unique(array_filter(array_map(
            static fn($name): string => is_string($name) ? trim($name) : '',
            $names ?? [],
        ))));
        $query = trim($query ?? '');

        if ($query === '' && $names === []) {
            return CallResult::json(['tools' => $this->catalogue($visible)]);
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
        if ($query !== '' && count($found) < $this->maxDescribed) {
            foreach ((new ToolSearch($visible, $this->searchOptions))->search($query, $this->maxDescribed) as $tool) {
                if (count($found) >= $this->maxDescribed) {
                    break;
                }
                $found[$tool->name] = $tool;
            }
        }

        $answer = ['tools' => array_values(array_map(fn(Tool $tool): array => [
            'name' => $tool->name,
            'title' => $tool->title(),
            'run_with' => $this->runnerFor($tool),
            'description' => $tool->description(),
            'inputSchema' => Json::schema($tool->inputSchema()),
        ], $found))];
        if ($unknown !== []) {
            $answer['unknown'] = $unknown;
        }
        if ($found === []) {
            $answer['hint'] = 'Nothing matches. Try other keywords in English, or call with no argument for every tool you may use.';
        }

        return CallResult::json($answer);
    }

    /**
     * Every visible tool, names only, grouped when the host says how.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogue(ToolSet $visible): array
    {
        $entry = fn(Tool $tool): array => ['name' => $tool->name, 'title' => $tool->title(), 'run_with' => $this->runnerFor($tool)];
        if ($this->groupOf === null) {
            return array_map($entry, $visible->all());
        }
        $groups = [];
        foreach ($visible as $tool) {
            $groups[($this->groupOf)($tool)][] = $entry($tool);
        }
        $list = [];
        foreach ($groups as $group => $tools) {
            $list[] = ['group' => (string) $group, 'tools' => $tools];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(bool $writes, array $arguments, ToolRunner $runner): CallResult
    {
        $name = $arguments['name'] ?? null;
        $inner = $arguments['arguments'] ?? [];
        if (!is_string($name) || $name === '') {
            return CallResult::error('name is required: the exact name of a tool, as the search gave it.');
        }
        if (!is_array($inner)) {
            return CallResult::error('arguments must be an object.');
        }
        if ($this->owns($name)) {
            return CallResult::error('A runner runs the tools a search finds, not the search or another runner.');
        }
        $tool = $runner->visible()->get($name);
        if ($tool !== null && $tool->readsOnly() === $writes) {
            return CallResult::error(sprintf('%s %s: run it with %s.', $name, $writes ? 'only reads' : 'changes something', $this->runnerFor($tool)));
        }
        /** @var array<string, mixed> $inner */
        $result = $runner->run($name, $inner);
        if ($tool !== null && $result->isError && $this->schemaOnError) {
            $schema = json_encode($tool->inputSchema(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($schema)) {
                $result = $result->withContent('Parameters of ' . $name . ': ' . $schema);
            }
        }

        return $result;
    }

    private function runnerFor(Tool $tool): string
    {
        return $tool->readsOnly() ? $this->readName : $this->writeName;
    }
}
