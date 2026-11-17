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

namespace Novalo\McpToolDiscovery\Mode\Grouped;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Mode\ToolFinder;
use Novalo\McpToolDiscovery\Protocol\Json;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * One tool per group of host tools, so the assistant sees the map of the
 * server without searching, in tens of tools rather than hundreds.
 *
 * Each group the host declares becomes two tools at most: one named after the
 * group, which runs its reads and describes any of its actions, and one with
 * the change suffix, which runs its changes. Two tools rather than one for the
 * same reason as the compact mode's two runners: destructiveHint is the only
 * lever a client offers to ask the person first, and a group mixes reads and
 * changes. Describing an action goes through the read tool, so reading a
 * schema never asks the person to confirm anything.
 *
 * The groups are declared up front rather than taken from the tools, because
 * whether a name is the mode's own must be known from the name alone. A tool
 * whose group is not declared is listed as itself, so it is never out of
 * the assistant's reach.
 */
final class GroupedMode implements DiscoveryMode
{
    public const CHANGE_SUFFIX = '_change';

    /** @var \Closure(Tool): string */
    private readonly \Closure $groupOf;

    /** @var array<string, string> the read tool's name => its group */
    private array $readNames = [];

    /** @var array<string, string> the change tool's name => its group */
    private array $changeNames = [];

    /**
     * @param callable(Tool): string $groupOf the group a tool belongs to
     * @param array<string, string> $groups each group's name => its title, as the host shows it to people, in the order to list them
     * @param string $prefix put before every group's tool names, e.g. "acme_" for acme_files
     * @param array<string, string> $changeTitles a group's name => the title of its change tool; "<title>: changes" when left out
     * @param int $maxDescribed the most actions one call describes
     * @param bool $schemaOnError add the action's parameters to a failed run, so the assistant can fix its call
     */
    public function __construct(
        callable $groupOf,
        private readonly array $groups,
        private readonly string $prefix = '',
        private readonly array $changeTitles = [],
        private readonly int $maxDescribed = 10,
        private readonly bool $schemaOnError = true,
    ) {
        $this->groupOf = \Closure::fromCallable($groupOf);
        if ($maxDescribed < 1) {
            throw new \InvalidArgumentException('A call must be able to describe at least one action.');
        }
        foreach (array_keys($groups) as $group) {
            $read = $prefix . $group;
            $change = $read . self::CHANGE_SUFFIX;
            // Checked here rather than when the list is built, so a group
            // the host misnamed fails at boot instead of on one client.
            if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $change) !== 1) {
                throw new \InvalidArgumentException(sprintf('The group "%s" does not make a valid tool name: letters, digits, "_", "-" or ".", 128 characters in all.', $group));
            }
            if (isset($this->readNames[$change]) || isset($this->changeNames[$read])) {
                throw new \InvalidArgumentException(sprintf('The group "%s" clashes with the change tool of another group.', $group));
            }
            $this->readNames[$read] = $group;
            $this->changeNames[$change] = $group;
        }
    }

    public function name(): string
    {
        return 'grouped';
    }

    public function listTools(ToolSet $visible): array
    {
        $members = $this->members($visible);
        $tools = [];
        foreach ($visible as $tool) {
            if (!isset($this->groups[$this->groupOf($tool)])) {
                $tools[] = $tool;
            }
        }
        foreach ($this->groups as $group => $title) {
            if (!isset($members[$group])) {
                continue;
            }
            [$reads, $changes] = $members[$group];
            $tools[] = $this->readTool($group, $title, $reads, $changes);
            if ($changes !== []) {
                $tools[] = $this->changeTool($group, $title, $changes);
            }
        }

        return $tools;
    }

    public function owns(string $name): bool
    {
        return isset($this->readNames[$name]) || isset($this->changeNames[$name]);
    }

    public function call(string $name, array $arguments, ToolRunner $runner): CallResult
    {
        if (isset($this->changeNames[$name])) {
            return $this->run($this->changeNames[$name], true, $arguments, $runner);
        }
        $group = $this->readNames[$name] ?? '';
        if (array_key_exists('describe', $arguments)) {
            return $this->describe($group, $arguments['describe'], $runner);
        }

        return $this->run($group, false, $arguments, $runner);
    }

    /**
     * The visible tools of each declared group, reads and changes apart, in
     * the host's order.
     *
     * @return array<string, array{0: list<Tool>, 1: list<Tool>}>
     */
    private function members(ToolSet $visible): array
    {
        $members = [];
        foreach ($visible as $tool) {
            $group = $this->groupOf($tool);
            if (!isset($this->groups[$group])) {
                continue;
            }
            $members[$group] ??= [[], []];
            $members[$group][$tool->readsOnly() ? 0 : 1][] = $tool;
        }

        return $members;
    }

    private function groupOf(Tool $tool): string
    {
        return ($this->groupOf)($tool);
    }

    /**
     * @param list<Tool> $reads
     * @param list<Tool> $changes
     */
    private function readTool(string $group, string $title, array $reads, array $changes): Tool
    {
        $name = $this->prefix . $group;
        $change = $name . self::CHANGE_SUFFIX;
        // Each action is named twice in the list, no more: once with its title
        // here or in the change tool, so the assistant knows what it does, and
        // once in the enum of the tool that runs it, so a client can check the
        // call. The changes are not repeated here and describe has no enum of
        // its own: both would only copy names the list already shows, and
        // describe answers an unknown name in its own list anyway.
        $lines = [];
        if ($reads !== []) {
            $lines[] = sprintf('%s. Runs one action of this group that only reads: give action and its arguments.', $title);
            $lines = [...$lines, ...array_map([self::class, 'line'], $reads)];
        } else {
            $lines[] = sprintf('%s.', $title);
        }
        if ($changes !== []) {
            $lines[] = sprintf('The changes of this group run with %s, which lists them.', $change);
        }
        $lines[] = 'To read the parameters of any action of the group first, read or change, give describe with its name.';
        $properties = [];
        if ($reads !== []) {
            $properties['action'] = ['type' => 'string', 'enum' => self::names($reads), 'description' => 'The read to run.'];
            $properties['arguments'] = ['type' => 'object', 'description' => 'The arguments of that action, following its inputSchema.'];
        }
        $properties['describe'] = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => sprintf('Names of actions of this group, reads or changes, to describe in full with their inputSchema; nothing runs. Up to %d.', $this->maxDescribed),
        ];

        return Tool::fromArray([
            'name' => $name,
            'title' => $title,
            'description' => implode("\n", $lines),
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
        ]);
    }

    /**
     * @param list<Tool> $changes
     */
    private function changeTool(string $group, string $title, array $changes): Tool
    {
        $name = $this->prefix . $group . self::CHANGE_SUFFIX;
        $lines = [
            sprintf('%s. Runs one action of this group that changes something: give action and its arguments. Ask the person before each call, as for any change. Read an action\'s parameters with %s (describe).', $title, $this->prefix . $group),
            ...array_map([self::class, 'line'], $changes),
        ];

        return Tool::fromArray([
            'name' => $name,
            'title' => $this->changeTitles[$group] ?? $title . ': changes',
            'description' => implode("\n", $lines),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => self::names($changes), 'description' => 'The change to run.'],
                    'arguments' => ['type' => 'object', 'description' => 'The arguments of that action, following its inputSchema.'],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ],
            // Destructive on purpose: the flag that makes a client ask first.
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false],
        ]);
    }

    private static function line(Tool $tool): string
    {
        $title = $tool->title();

        // A tool without a title would otherwise read "- name: name".
        return $title === $tool->name ? '- ' . $tool->name : '- ' . $tool->name . ': ' . $title;
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, $tools);
    }

    private function describe(string $group, mixed $names, ToolRunner $runner): CallResult
    {
        if (!is_array($names) || !array_is_list($names) || $names === []) {
            return CallResult::error('describe is a list of action names of this group.');
        }
        $found = [];
        $unknown = [];
        foreach (array_slice(array_values(array_unique(array_filter($names, 'is_string'))), 0, $this->maxDescribed) as $name) {
            $tool = $runner->visible()->get($name);
            if ($tool === null || $this->groupOf($tool) !== $group) {
                // One list for every reason: a hidden action reads exactly
                // like one that does not exist, or belongs to another group.
                $unknown[] = ToolRunner::printable($name);
                continue;
            }
            $found[] = [
                'name' => $tool->name,
                'title' => $tool->title(),
                'run_with' => $this->runnerFor($tool),
                'description' => $tool->description(),
                'inputSchema' => Json::schema($tool->inputSchema()),
            ];
        }
        $answer = ['tools' => $found];
        if ($unknown !== []) {
            $answer['unknown'] = $unknown;
        }

        return CallResult::json($answer, $found === []);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(string $group, bool $changes, array $arguments, ToolRunner $runner): CallResult
    {
        $action = $arguments['action'] ?? null;
        $inner = $arguments['arguments'] ?? [];
        if (!is_string($action) || $action === '') {
            return CallResult::error('action is required: the name of one of the actions this tool lists.');
        }
        if (!ToolRunner::isObject($inner)) {
            return CallResult::error('arguments must be an object.');
        }
        $tool = $runner->visible()->get($action);
        if ($tool !== null) {
            $actual = $this->groupOf($tool);
            if (!isset($this->groups[$actual])) {
                return CallResult::error(sprintf('%s is not in a group: call it by its own name.', $action));
            }
            if ($actual !== $group || $tool->readsOnly() === $changes) {
                return CallResult::error(sprintf('%s is not an action of this tool: run it with %s.', $action, $this->runnerFor($tool)));
            }
        }
        // A hidden or unknown action goes to the runner as it is, so its
        // answer is the runner's own and the gate is never asked about it.
        $result = $runner->run($action, $inner);

        return $tool !== null && $result->isError && $this->schemaOnError ? ToolFinder::withParameters($result, $tool) : $result;
    }

    private function runnerFor(Tool $tool): string
    {
        $group = $this->groupOf($tool);
        if (!isset($this->groups[$group])) {
            return $tool->name;
        }

        return $this->prefix . $group . ($tool->readsOnly() ? '' : self::CHANGE_SUFFIX);
    }
}
