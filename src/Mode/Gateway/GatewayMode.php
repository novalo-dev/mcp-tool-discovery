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

namespace Novalo\McpToolDiscovery\Mode\Gateway;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Mode\GuideHeader;
use Novalo\McpToolDiscovery\Mode\Instructions;
use Novalo\McpToolDiscovery\Mode\ToolFinder;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * A single tool that searches, describes and runs, for clients that accept
 * very few tools and for small models.
 *
 * One tool can carry one set of annotations, and destructiveHint is the only
 * lever a client offers to ask the person before a change. So the gateway is
 * marked destructive as soon as the caller may run a change through it, and
 * marked as a read when every tool it reaches only reads. A host whose client
 * asks before every destructive call, and that would rather not confirm each
 * search, sets $changesApart: the changes are then listed as themselves, with
 * their own annotations, and the gateway only reads.
 */
final class GatewayMode implements DiscoveryMode, GuideHeader, Instructions
{
    public const NAME = 'use_tools';

    public const SEARCH = 'search';
    public const DESCRIBE = 'describe';
    public const RUN = 'run';

    private readonly string $toolName;

    private readonly ToolFinder $finder;

    /**
     * @param string $prefix put before the tool's name, e.g. "acme_" for acme_use_tools
     * @param string|null $title the title shown to people, translated by the host
     * @param bool $changesApart list the tools that change something as themselves, so the gateway only reads
     * @param (callable(Tool): string)|null $groupOf the group a tool belongs to, for the list a search with no argument gives
     * @param int $maxDescribed the most tools one search or description gives in full
     * @param bool $schemaOnError add the tool's parameters to a failed run, so the assistant can fix its call
     */
    public function __construct(
        string $prefix = '',
        private readonly ?string $title = null,
        private readonly bool $changesApart = false,
        ?callable $groupOf = null,
        private readonly int $maxDescribed = 10,
        private readonly bool $schemaOnError = true,
    ) {
        $this->toolName = $prefix . self::NAME;
        // At boot, as the other modes do: a bad prefix would otherwise fail
        // on every tools/list while tools/call still answers.
        if (!Tool::validName($this->toolName)) {
            throw new \InvalidArgumentException(sprintf('The prefix "%s" does not make a valid tool name: %s must be 1 to 128 letters, digits, "_", "-" or ".".', $prefix, $this->toolName));
        }
        $this->finder = new ToolFinder(
            $this->runWith(...),
            $maxDescribed,
            $groupOf,
            sprintf('Say it again with words from vocabulary, or call %s with operation search and nothing else for every tool you may use.', $this->toolName),
            // With the changes apart, run_with already names the change; through
            // the gateway alone, every tool answers to the same run_with.
            !$changesApart,
        );
    }

    public function name(): string
    {
        return 'gateway';
    }

    public function listTools(ToolSet $visible): array
    {
        $changes = array_values(array_filter($visible->all(), static fn(Tool $tool): bool => !$tool->readsOnly()));
        if ($this->changesApart) {
            return [$this->gateway(false), ...$changes];
        }

        return [$this->gateway($changes !== [])];
    }

    public function owns(string $name): bool
    {
        return $name === $this->toolName;
    }

    public function call(string $name, array $arguments, ToolRunner $runner): CallResult
    {
        $operation = $arguments['operation'] ?? null;
        unset($arguments['operation']);

        return match ($operation) {
            // Only the search fields reach the search: a stray name or names
            // must not turn a search into a description.
            self::SEARCH => $this->finder->find(array_intersect_key($arguments, array_flip(['action', 'object', 'description', 'query'])), $runner),
            self::DESCRIBE => $this->describe($arguments, $runner),
            self::RUN => $this->run($arguments, $runner),
            default => CallResult::error(sprintf('operation is required: %s, %s or %s.', self::SEARCH, self::DESCRIBE, self::RUN)),
        };
    }

    /**
     * The sentences for the server's instructions: one tool stands for the
     * whole server, and with the changes apart, the changes are called as
     * themselves and refused by the gateway.
     */
    public function instructions(): string
    {
        if ($this->changesApart) {
            return sprintf('The tools of this server that only read go through %s: find the ones you need with operation %s, read their parameters with operation %s, and run each with operation %s. A tool that changes something is in your tool list: call it directly.', $this->toolName, self::SEARCH, self::DESCRIBE, self::RUN);
        }

        return sprintf('Every tool of this server goes through %s: find the ones you need with operation %s, read their parameters with operation %s, and run each with operation %s.', $this->toolName, self::SEARCH, self::DESCRIBE, self::RUN);
    }

    /**
     * The block a host puts at the top of a guide that names tools: through
     * the gateway, none of them is in the assistant's list. With the changes
     * apart, the changes the guide names are, and get a line of their own,
     * so the assistant does not send them to the gateway, which refuses them.
     */
    public function guideHeader(iterable $names, ToolSet $visible): string
    {
        $through = [];
        $direct = [];
        foreach ($names as $name) {
            $tool = $visible->get($name);
            if ($tool === null || $this->owns($name)) {
                continue;
            }
            if ($this->runWith($tool) === $tool->name) {
                $direct[] = $name;
            } else {
                $through[] = $name;
            }
        }
        if ($through === []) {
            return '';
        }
        $lines = [
            '## How to call the tools of this guide',
            '',
            sprintf('Every tool of this server goes through %s: the tools this guide names are not in your tool list', $this->toolName)
                . ($direct === [] ? '. ' : ', except the ones it says to call directly. ')
                . sprintf('Read the parameters of the ones you need with operation %s (names), then run each with operation %s (name and arguments):', self::DESCRIBE, self::RUN),
            sprintf('- through %s: %s', $this->toolName, implode(', ', $through)),
        ];
        if ($direct !== []) {
            $lines[] = '- directly, as they are in your list: ' . implode(', ', $direct);
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function gateway(bool $runsChanges): Tool
    {
        $runs = $this->changesApart
            ? 'run (name and arguments) runs a tool that only reads; a tool that changes something is in your tool list, call it by its own name.'
            : 'run (name and arguments) runs one of them' . ($runsChanges ? '; ask the person before running a tool marked changes.' : '.');

        return Tool::fromArray([
            'name' => $this->toolName,
            'title' => $this->title ?? 'Find and run the tools',
            'description' => implode(' ', [
                'Every tool of this server goes through this one. Give operation:',
                sprintf('search (action, object and description, in English: what to do, what it is done to, the request in a short sentence; or query, free keywords) finds the tools you may use, up to %d, each with its inputSchema;', $this->maxDescribed),
                'search with nothing else lists every tool you may use, names only;',
                'describe (names) gives the inputSchema of the tools a guide names;',
                $runs,
            ]),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'operation' => ['type' => 'string', 'enum' => [self::SEARCH, self::DESCRIBE, self::RUN], 'description' => 'What this call does.'],
                    'action' => ['type' => 'string', 'description' => 'search: what to do, one or two words, e.g. "change", "list", "restore".'],
                    'object' => ['type' => 'string', 'description' => 'search: what it is done to, e.g. "ticket status", "backup".'],
                    'description' => ['type' => 'string', 'description' => 'search: the request in a short sentence, e.g. "move the ticket to done".'],
                    'query' => ['type' => 'string', 'description' => 'search: free keywords, when the request does not split into fields. The fields find the right tool more often.'],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => sprintf('describe: exact tool names. Up to %d.', $this->maxDescribed)],
                    'name' => ['type' => 'string', 'description' => 'run: the exact name of the tool, as search or describe gave it.'],
                    'arguments' => ['type' => 'object', 'description' => 'run: the arguments of that tool, following its inputSchema.'],
                    // Always in the schema, since the list is built without the
                    // host's search settings; a search only gives one out when
                    // the host learns.
                    'search_id' => ['type' => 'string', 'description' => 'run: the search_id the search gave with the tool, if it gave one, so the next searches find it sooner.'],
                ],
                'required' => ['operation'],
                'additionalProperties' => false,
            ],
            // Destructive whenever a change can go through: the flag is what
            // makes a client ask the person first, and a single tool cannot
            // ask for some calls and not others.
            'annotations' => $runsChanges
                ? ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false]
                : ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function describe(array $arguments, ToolRunner $runner): CallResult
    {
        $names = $arguments['names'] ?? null;
        if (!is_array($names) || $names === []) {
            return CallResult::error('names is required: a list of exact tool names.');
        }

        return $this->finder->find(['names' => $names], $runner);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(array $arguments, ToolRunner $runner): CallResult
    {
        $name = $arguments['name'] ?? null;
        $inner = $arguments['arguments'] ?? [];
        if (!is_string($name) || $name === '') {
            return CallResult::error('name is required: the exact name of a tool, as search gave it.');
        }
        if (!ToolRunner::isObject($inner)) {
            return CallResult::error('arguments must be an object.');
        }
        if ($this->owns($name)) {
            return CallResult::error('run runs the tools a search finds, not this tool itself.');
        }
        $tool = $runner->visible()->get($name);
        if ($tool !== null && $this->changesApart && !$tool->readsOnly()) {
            // Refused before the gate: through here the change would skip the
            // confirmation its own listing carries.
            return CallResult::error(sprintf('%s changes something: call it by its own name.', $name));
        }
        $searchId = $arguments['search_id'] ?? null;
        // A hidden or unknown name goes to the runner as it is, so its answer
        // is the runner's own and the gate is never asked about it.
        $result = $runner->run($name, $inner, is_string($searchId) ? $searchId : null);

        return $tool !== null && $result->isError && $this->schemaOnError ? ToolFinder::withParameters($result, $tool) : $result;
    }

    private function runWith(Tool $tool): string
    {
        return $this->changesApart && !$tool->readsOnly() ? $tool->name : $this->toolName;
    }
}
