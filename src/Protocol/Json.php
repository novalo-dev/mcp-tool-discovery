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

namespace Novalo\McpToolDiscovery\Protocol;

/**
 * Keeps JSON objects objects when PHP arrays are encoded.
 *
 * PHP has one array type for lists and maps, and json_encode() writes an
 * empty one as [] - a list. A tool with no parameter then sends
 * "properties": [], which every revision's schema rejects, and a strict
 * client drops the tool. Every PHP MCP server hits this once; the library
 * fixes it where it builds answers, so no host has to.
 */
final class Json
{
    /**
     * Keywords of JSON Schema whose value maps names to schemas.
     */
    private const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /**
     * Keywords of JSON Schema whose value is one schema.
     */
    private const SCHEMA_VALUES = ['items', 'additionalProperties', 'not', 'if', 'then', 'else', 'contains', 'propertyNames', 'unevaluatedItems', 'unevaluatedProperties'];

    /**
     * Keywords of JSON Schema whose value is a list of schemas.
     */
    private const SCHEMA_LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * A JSON Schema with every place that must be an object made one.
     *
     * @param array<mixed> $schema
     *
     * @return array<mixed>|\stdClass
     */
    public static function schema(array $schema): array|\stdClass
    {
        if ($schema === []) {
            return new \stdClass();
        }
        foreach ($schema as $keyword => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (in_array($keyword, self::SCHEMA_MAPS, true)) {
                $map = [];
                foreach ($value as $name => $sub) {
                    $map[$name] = is_array($sub) ? self::schema($sub) : $sub;
                }
                $schema[$keyword] = $map === [] ? new \stdClass() : $map;
            } elseif (in_array($keyword, self::SCHEMA_VALUES, true)) {
                $schema[$keyword] = self::schema($value);
            } elseif (in_array($keyword, self::SCHEMA_LISTS, true)) {
                $schema[$keyword] = array_map(static fn($sub) => is_array($sub) ? self::schema($sub) : $sub, $value);
            }
        }

        return $schema;
    }

    /**
     * An object member: an empty array becomes {}.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>|\stdClass
     */
    public static function object(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass() : $value;
    }
}
