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
 * One MCP tool, as the host describes it in `tools/list`.
 *
 * Kept as the definition array the host already has, so any PHP MCP server can
 * hand its list over without an adapter. Only what the library relies on is
 * checked: the name, which every mode routes on, and an object input schema,
 * which every revision of the specification requires.
 */
final class Tool
{
    /**
     * Names of the 2025-11-25 specification: letters, digits, underscore,
     * hyphen and dot, 1 to 128 characters. Earlier revisions said nothing, and
     * holding every tool to the strictest rule keeps names valid everywhere.
     */
    private const NAME = '/^[A-Za-z0-9_.-]{1,128}$/';

    /**
     * @param array<string, mixed> $definition
     */
    private function __construct(
        public readonly string $name,
        private readonly array $definition,
    ) {}

    /**
     * @param array<string, mixed> $definition a Tool object of the MCP specification
     *
     * @throws InvalidTool
     */
    public static function fromArray(array $definition): self
    {
        $name = $definition['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME, $name) !== 1) {
            throw InvalidTool::because(is_string($name) ? $name : '?', 'the name must be 1 to 128 letters, digits, "_", "-" or "."');
        }
        $schema = $definition['inputSchema'] ?? null;
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            throw InvalidTool::because($name, 'inputSchema must be a JSON Schema with "type": "object"');
        }
        foreach (['title', 'description'] as $field) {
            if (isset($definition[$field]) && !is_string($definition[$field])) {
                throw InvalidTool::because($name, sprintf('%s must be a string', $field));
            }
        }
        if (isset($definition['annotations']) && !is_array($definition['annotations'])) {
            throw InvalidTool::because($name, 'annotations must be an object');
        }

        return new self($name, $definition);
    }

    /**
     * The name a person reads: the title, then the title the 2025-03-26
     * revision kept in the annotations, then the name itself.
     */
    public function title(): string
    {
        $title = $this->definition['title'] ?? $this->annotations()['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : $this->name;
    }

    public function description(): string
    {
        $description = $this->definition['description'] ?? '';

        return is_string($description) ? $description : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        /** @var array<string, mixed> $schema checked in fromArray() */
        $schema = $this->definition['inputSchema'];

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        /** @var array<string, mixed> $annotations an object of the specification, checked in fromArray() */
        $annotations = $this->definition['annotations'] ?? [];

        return $annotations;
    }

    /**
     * Whether the tool says it changes nothing.
     *
     * Only an explicit `readOnlyHint: true` counts as a read. A tool that says
     * nothing is treated as a change, as the specification's own default is,
     * so a forgotten annotation asks for confirmation rather than skipping it.
     */
    public function readsOnly(): bool
    {
        return ($this->annotations()['readOnlyHint'] ?? false) === true;
    }

    /**
     * The definition exactly as the host gave it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition;
    }
}
