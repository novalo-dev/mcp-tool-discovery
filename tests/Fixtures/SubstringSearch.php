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

namespace Novalo\McpToolDiscovery\Tests\Fixtures;

use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * The ranking of `ToolSearch::search()` in laravel/mcp v1.2.1, the official
 * MCP package of Laravel, written again without Laravel so the benchmark can
 * run it on its own servers.
 *
 * A host choosing a search reads a score better beside the one it would get
 * otherwise. This is the search a PHP host most likely has already: words
 * cut at anything that is not a letter or a digit, then for each word 4 if
 * the name holds it, 2 if the description does, 1 if the input schema's JSON
 * does, and 8 more when the words are exactly those of the name. No title, no
 * index, no learning; ties keep the order of the list.
 *
 * The scoring is that of laravel/mcp, MIT licence, (c) Taylor Otwell:
 * https://github.com/laravel/mcp/blob/v1.2.1/src/Server/Tools/ToolSearch.php
 * Only what decides the order is kept, so a change on their side is copied
 * here by hand and the benchmark says which version it measures.
 */
final class SubstringSearch
{
    public const VERSION = 'laravel/mcp v1.2.1';

    /**
     * @return list<string> the names of the tools that score, best first
     */
    public static function rank(ToolSet $tools, string $query): array
    {
        $terms = self::terms($query);
        $candidates = [];
        foreach ($tools->all() as $index => $tool) {
            $score = self::score($tool, $terms);
            if ($terms === [] || $score > 0) {
                $candidates[] = [$index, $score, $tool->name];
            }
        }
        usort($candidates, static fn(array $left, array $right): int => $right[1] <=> $left[1] ?: $left[0] <=> $right[0]);

        return array_column($candidates, 2);
    }

    /**
     * @param list<string> $terms
     */
    private static function score(Tool $tool, array $terms): int
    {
        $name = mb_strtolower($tool->name);
        $description = mb_strtolower($tool->description());
        $schema = mb_strtolower(json_encode($tool->inputSchema(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
        $score = $terms !== [] && $terms === self::terms($name) ? 8 : 0;
        foreach ($terms as $term) {
            $score += str_contains($name, $term) ? 4 : 0;
            $score += str_contains($description, $term) ? 2 : 0;
            $score += str_contains($schema, $term) ? 1 : 0;
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private static function terms(string $text): array
    {
        return array_values(array_filter(
            preg_split('/[^\pL\pN]+/u', mb_strtolower($text)) ?: [],
            static fn(string $term): bool => $term !== '',
        ));
    }
}
