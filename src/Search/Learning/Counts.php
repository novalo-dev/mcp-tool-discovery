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

namespace Novalo\McpToolDiscovery\Search\Learning;

/**
 * The counts both stores keep, and the limit to their size.
 *
 * Every run can add a pair of words the store has never seen, and the pairs
 * of a server's words are many, so a store that only grows ends up read in
 * full by every search. The stores keep the most used counts instead.
 *
 * @internal
 */
final class Counts
{
    /**
     * The most (word, tool) counts a store keeps. Learning every request of
     * the 176-tool benchmark server keeps under 4,000.
     */
    public const LIMIT = 20_000;

    /**
     * The counts with one more run of the tool for each word.
     *
     * @param array<string, array<string, int>> $counts
     * @param list<string> $words
     *
     * @return array<string, array<string, int>>
     */
    public static function add(array $counts, array $words, string $tool, int $ceiling = PHP_INT_MAX): array
    {
        foreach (array_unique($words) as $word) {
            $counts[$word][$tool] = min($ceiling, ($counts[$word][$tool] ?? 0) + 1);
        }

        return self::bounded($counts);
    }

    /**
     * The counts cut to LIMIT, the least run going first and, among equal
     * counts, the words the store has held longest, so a word just learned
     * is not the first thing dropped.
     *
     * @param array<string, array<string, int>> $counts
     *
     * @return array<string, array<string, int>>
     */
    private static function bounded(array $counts): array
    {
        $total = array_sum(array_map('count', $counts));
        if ($total <= self::LIMIT) {
            return $counts;
        }
        /** @var list<array{int, string, string}> $entries */
        $entries = [];
        foreach ($counts as $word => $tools) {
            foreach ($tools as $tool => $runs) {
                $entries[] = [$runs, (string) $word, (string) $tool];
            }
        }
        // usort is stable since PHP 8.0: equal counts keep the store's order.
        usort($entries, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        foreach (array_slice($entries, 0, $total - self::LIMIT) as [, $word, $tool]) {
            unset($counts[$word][$tool]);
            if ($counts[$word] === []) {
                unset($counts[$word]);
            }
        }

        return $counts;
    }
}
