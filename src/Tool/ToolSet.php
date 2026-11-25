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

namespace Novalo\McpToolDiscovery\Tool;

use Novalo\McpToolDiscovery\Exception\InvalidTool;

/**
 * The tools of a host, in the host's order.
 *
 * Immutable: a mode receives the set of tools the caller may see and cannot
 * add one to it. The order is kept because it is the tie-breaker everywhere,
 * so the same request lists the same tools in the same order every time.
 *
 * @implements \IteratorAggregate<string, Tool>
 */
final class ToolSet implements \IteratorAggregate, \Countable
{
    /**
     * @param array<array-key, Tool> $tools keyed by name; PHP turns a name such
     *        as "123" into an integer key, so keys are never handed out as they are
     */
    private function __construct(private readonly array $tools) {}

    /**
     * @param iterable<Tool|array<string, mixed>>                       $tools   Tool objects or MCP definitions
     * @param (callable(InvalidTool, Tool|array<string, mixed>): void)|null $refused when given, a definition
     *        the library cannot work with, or a later tool under a name already taken, is left out and
     *        handed here with the reason; without it, the first one fails the whole set
     *
     * @throws InvalidTool on a malformed definition or a name given twice, when no $refused is given
     */
    public static function of(iterable $tools, ?callable $refused = null): self
    {
        $byName = [];
        foreach ($tools as $definition) {
            try {
                $tool = $definition instanceof Tool ? $definition : Tool::fromArray($definition);
                if (isset($byName[$tool->name])) {
                    // Two tools under one name would make every call ambiguous.
                    throw InvalidTool::because($tool->name, 'the name is used twice');
                }
            } catch (InvalidTool $error) {
                if ($refused === null) {
                    throw $error;
                }
                // A host that gathers tools from parts it does not write (plugins,
                // other packages) would otherwise lose every tool to one bad
                // definition. The first tool under a name is the one kept, as a
                // registry that refuses a name already taken keeps it.
                $refused($error, $definition);

                continue;
            }
            $byName[$tool->name] = $tool;
        }

        return new self($byName);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @param callable(Tool): bool $keep
     */
    public function filter(callable $keep): self
    {
        return new self(array_filter($this->tools, $keep));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, array_values($this->tools));
    }

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * @return \Generator<string, Tool>
     */
    public function getIterator(): \Generator
    {
        // The tool's own name, not the array key, so a host that iterates
        // with strict types gets a string for a tool named "123" too.
        foreach ($this->tools as $tool) {
            yield $tool->name => $tool;
        }
    }

    public function count(): int
    {
        return count($this->tools);
    }
}
