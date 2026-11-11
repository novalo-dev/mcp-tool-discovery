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
     * @param array<string, Tool> $tools keyed by name
     */
    private function __construct(private readonly array $tools) {}

    /**
     * @param iterable<Tool|array<string, mixed>> $tools Tool objects or MCP definitions
     *
     * @throws InvalidTool on a malformed definition or a name given twice
     */
    public static function of(iterable $tools): self
    {
        $byName = [];
        foreach ($tools as $tool) {
            $tool = $tool instanceof Tool ? $tool : Tool::fromArray($tool);
            if (isset($byName[$tool->name])) {
                // Two tools under one name would make every call ambiguous.
                throw InvalidTool::because($tool->name, 'the name is used twice');
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
        return array_keys($this->tools);
    }

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * @return \ArrayIterator<string, Tool>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->tools);
    }

    public function count(): int
    {
        return count($this->tools);
    }
}
