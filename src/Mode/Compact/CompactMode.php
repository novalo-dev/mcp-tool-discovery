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
use Novalo\McpToolDiscovery\Mode\ToolFinder;
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
 *
 * A host may add a fourth tool, off by default: a runner that applies one
 * change to a list of items in one call, for an assistant that would
 * otherwise make and confirm thirty calls of the change runner. Each item
 * goes through the runner on its own, so the host's gate judges it exactly
 * as a single call.
 */
final class CompactMode implements DiscoveryMode
{
    public const SEARCH = 'search_tools';
    public const RUN_READ = 'run_read_tool';
    public const RUN_WRITE = 'run_write_tool';
    public const RUN_WRITE_BATCH = 'run_write_batch';

    private readonly string $searchName;
    private readonly string $readName;
    private readonly string $writeName;
    private readonly string $batchName;

    private readonly ToolFinder $finder;

    /**
     * @param string $prefix put before the three names, e.g. "acme_" for acme_search_tools
     * @param list<string> $pinned host tools listed as themselves next to the three, e.g. a ping
     * @param array{search?: string, read?: string, write?: string, batch?: string} $titles the titles shown to people, translated by the host
     * @param (callable(Tool): string)|null $groupOf the group a tool belongs to, for the list a search with no argument gives
     * @param int $maxDescribed the most tools one search describes in full
     * @param bool $schemaOnError add the tool's parameters to a failed run, so the assistant can fix its call
     * @param int $batchLimit the most items one call of run_write_batch takes; 0, the default, leaves that runner out
     */
    public function __construct(
        string $prefix = '',
        private readonly array $pinned = [],
        private readonly array $titles = [],
        ?callable $groupOf = null,
        private readonly int $maxDescribed = 10,
        private readonly bool $schemaOnError = true,
        private readonly int $batchLimit = 0,
    ) {
        $this->searchName = $prefix . self::SEARCH;
        $this->readName = $prefix . self::RUN_READ;
        $this->writeName = $prefix . self::RUN_WRITE;
        $this->batchName = $prefix . self::RUN_WRITE_BATCH;
        $this->finder = new ToolFinder($this->runnerFor(...), $maxDescribed, $groupOf);
        if ($batchLimit < 0) {
            throw new \InvalidArgumentException('The batch limit is a number of items, 0 to leave the batch runner out.');
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
        return $name === $this->searchName || $name === $this->readName || $name === $this->writeName
            // Only when it is listed: with the batch runner off, a host tool
            // of that name stays the host's.
            || ($this->batchLimit > 0 && $name === $this->batchName);
    }

    public function call(string $name, array $arguments, ToolRunner $runner): CallResult
    {
        return match (true) {
            $name === $this->searchName => $this->finder->find($arguments, $runner),
            $this->batchLimit > 0 && $name === $this->batchName => $this->runBatch($arguments, $runner),
            default => $this->run($name === $this->writeName, $arguments, $runner),
        };
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
            if ($this->batchLimit > 0) {
                $lines[] = sprintf('- the same change on several items: %s, once for the whole list', $this->batchName);
            }
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * @return list<Tool>
     */
    private function ownTools(): array
    {
        $tools = [
            Tool::fromArray([
                'name' => $this->searchName,
                'title' => $this->titles['search'] ?? 'Search the tools',
                'description' => sprintf(
                    'Finds the tools you may use on this server and gives their parameters. The server lists its tools in compact form: call this first, then run what you found with %s (reads) or %s (changes), as each result says. Say what you look for in separate fields, in English: action, object and description. Or give the exact names a guide gives. With nothing, lists every tool you may use, names only. Reads only.',
                    $this->readName,
                    $this->writeName,
                ),
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'What to do, one or two words, e.g. "change", "list", "restore".',
                        ],
                        'object' => [
                            'type' => 'string',
                            'description' => 'What it is done to, e.g. "ticket status", "backup".',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => sprintf('The request in a short sentence, e.g. "move the ticket to done". Up to %d tools come back, best match first, each with its inputSchema.', $this->maxDescribed),
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Free keywords, when the request does not split into fields. The fields find the right tool more often.',
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
            $this->runner($this->readName, $this->titles['read'] ?? 'Run a reading tool', 'Runs one tool that only reads, found with ' . $this->searchName . '. A tool that changes something is refused here: run it with ' . $this->writeName . '.', true),
            $this->runner($this->writeName, $this->titles['write'] ?? 'Run a tool that changes something', 'Runs one tool that changes something, found with ' . $this->searchName . '. Ask the person before each call, as for any change. A tool that only reads is refused here: run it with ' . $this->readName . '.', false),
        ];
        if ($this->batchLimit > 0) {
            $tools[] = $this->batchRunner();
        }

        return $tools;
    }

    private function batchRunner(): Tool
    {
        return Tool::fromArray([
            'name' => $this->batchName,
            'title' => $this->titles['batch'] ?? 'Run one change on several items',
            'description' => sprintf(
                'Runs one tool that changes something, found with %s, once for each item of a list, in one call: up to %d items. Before the call, show the person the complete list of items with what will change for each, and ask once for the whole list. Each item is checked and run on its own, as a call of %s would be; the answer says for each item whether it succeeded or why it was refused, and one failure never stops the others. A tool that only reads is refused here: run it with %s.',
                $this->searchName,
                $this->batchLimit,
                $this->writeName,
                $this->readName,
            ),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The exact name of the tool, as the search gave it. One tool for the whole list.'],
                    'items' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                        'minItems' => 1,
                        'maxItems' => $this->batchLimit,
                        'description' => 'The arguments of each run, one object per item, each following the tool\'s inputSchema.',
                    ],
                    'search_id' => ['type' => 'string', 'description' => 'The search_id the search gave with the tool, if it gave one, so the next searches find it sooner.'],
                ],
                'required' => ['name', 'items'],
                'additionalProperties' => false,
            ],
            // Destructive like the single change runner: one confirmation of
            // the whole list is the point, never no confirmation at all.
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false],
        ]);
    }

    private function runner(string $name, string $title, string $description, bool $reads): Tool
    {
        $properties = [
            'name' => ['type' => 'string', 'description' => 'The exact name of the tool, as the search gave it.'],
            'arguments' => ['type' => 'object', 'description' => 'The arguments of that tool, following its inputSchema.'],
            // Always in the schema, since the list is built without the
            // host's search settings; a search only gives one out when the
            // host learns, so a client never has a value to send otherwise.
            'search_id' => ['type' => 'string', 'description' => 'The search_id the search gave with the tool, if it gave one, so the next searches find it sooner.'],
        ];

        return Tool::fromArray([
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
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
    private function run(bool $writes, array $arguments, ToolRunner $runner): CallResult
    {
        $name = $arguments['name'] ?? null;
        $inner = $arguments['arguments'] ?? [];
        if (!is_string($name) || $name === '') {
            return CallResult::error('name is required: the exact name of a tool, as the search gave it.');
        }
        if (!ToolRunner::isObject($inner)) {
            return CallResult::error('arguments must be an object.');
        }
        if ($this->owns($name)) {
            return CallResult::error('A runner runs the tools a search finds, not the search or another runner.');
        }
        $tool = $runner->visible()->get($name);
        if ($tool !== null && $tool->readsOnly() === $writes) {
            return CallResult::error(sprintf('%s %s: run it with %s.', $name, $writes ? 'only reads' : 'changes something', $this->runnerFor($tool)));
        }
        $searchId = $arguments['search_id'] ?? null;
        $result = $runner->run($name, $inner, is_string($searchId) ? $searchId : null);

        return $tool !== null && $result->isError && $this->schemaOnError ? ToolFinder::withParameters($result, $tool) : $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runBatch(array $arguments, ToolRunner $runner): CallResult
    {
        $name = $arguments['name'] ?? null;
        $items = $arguments['items'] ?? null;
        if (!is_string($name) || $name === '') {
            return CallResult::error('name is required: the exact name of a tool, as the search gave it.');
        }
        // The whole list is checked before anything runs: a malformed list
        // half applied would leave the person with changes nobody can name.
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            return CallResult::error('items is required: a list of objects, the arguments of each run.');
        }
        if (count($items) > $this->batchLimit) {
            return CallResult::error(sprintf('At most %d items in one call; nothing was run. Split the list.', $this->batchLimit));
        }
        foreach ($items as $index => $item) {
            if (!ToolRunner::isObject($item)) {
                return CallResult::error(sprintf('Item %d is not an object; nothing was run.', $index));
            }
        }
        if ($this->owns($name)) {
            return CallResult::error('A runner runs the tools a search finds, not the search or another runner.');
        }
        $tool = $runner->visible()->get($name);
        if ($tool === null) {
            // The runner's own answer, so a hidden tool reads exactly like
            // one that does not exist; the gate is never asked about it.
            return $runner->run($name, [], null);
        }
        if ($tool->readsOnly()) {
            return CallResult::error(sprintf('%s only reads: run it with %s.', $name, $this->readName));
        }

        $searchId = $arguments['search_id'] ?? null;
        $searchId = is_string($searchId) && $searchId !== '' ? $searchId : null;
        $results = [];
        $succeeded = 0;
        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $item */
            // Learned once per batch, from the first item that succeeds: thirty
            // items of one list are one answer to the search, not thirty.
            $result = $runner->run($name, $item, $succeeded === 0 ? $searchId : null);
            if (!$result->isError) {
                ++$succeeded;
            }
            $entry = ['item' => $index, 'isError' => $result->isError, 'content' => $result->content];
            if ($result->structuredContent !== null) {
                $entry['structuredContent'] = $result->structuredContent;
            }
            $results[] = $entry;
        }

        $failed = count($items) - $succeeded;
        // An error only when nothing succeeded: a partial batch changed
        // things, and a client that reads isError as "nothing happened"
        // would hide that from the person.
        $answer = CallResult::json(['name' => $name, 'succeeded' => $succeeded, 'failed' => $failed, 'results' => $results], $succeeded === 0);

        return $failed > 0 && $this->schemaOnError ? ToolFinder::withParameters($answer, $tool) : $answer;
    }

    private function runnerFor(Tool $tool): string
    {
        return $tool->readsOnly() ? $this->readName : $this->writeName;
    }
}
