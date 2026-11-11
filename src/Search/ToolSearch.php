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

namespace Novalo\McpToolDiscovery\Search;

use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * Finds the tools that answer a query, the best first.
 *
 * Three signals, added up:
 * - BM25F text relevance over the name, title, description and parameters;
 * - verb intent: the query's action word ("change", "turn off") against the
 *   verb the tool name starts with ("set_");
 * - object coverage: how much of the rest of the name ("order_status") the
 *   query names, which tells set_product_active from set_module_active.
 * Then everything far below the best is cut, so an assistant receives a few
 * schemas rather than the whole list.
 */
final class ToolSearch
{
    private readonly Index $index;
    private readonly Analyzer $analyzer;
    private readonly string $prefix;

    /** @var array<string, array{verb: string, objects: list<string>}> */
    private array $names = [];

    public function __construct(
        private readonly ToolSet $tools,
        private readonly SearchOptions $options = new SearchOptions(),
    ) {
        $this->analyzer = new Analyzer();
        $this->prefix = $options->namePrefix ?? self::commonPrefix($tools->names());
        $documents = [];
        foreach ($tools as $name => $tool) {
            $words = $this->analyzer->words(self::stripPrefix($name, $this->prefix));
            $this->names[$name] = ['verb' => $words[0] ?? '', 'objects' => array_slice($words, 1)];
            $documents[$name] = [
                'name' => implode(' ', $words),
                'title' => $tool->title(),
                'description' => $tool->description(),
                'parameters' => self::parameterText($tool),
            ];
        }
        $this->index = Index::build($documents, $options->weights, $this->analyzer, $options->trigramWeight);
    }

    /**
     * @return list<Tool> best first; empty when nothing matches
     */
    public function search(string $query, ?int $limit = null): array
    {
        $query = $this->expand($query);
        $words = $this->analyzer->words($query);
        $scores = $this->index->score($query);
        if ($scores === []) {
            return [];
        }
        $verbs = VerbIntent::verbsFor($words);
        $ranked = [];
        $position = 0;
        foreach ($scores as $name => $score) {
            $name = (string) $name;
            $parts = $this->names[$name];
            if ($verbs !== [] && isset($verbs[$parts['verb']])) {
                $score += $this->options->verbBonus;
            }
            if ($parts['objects'] !== []) {
                $covered = 0;
                foreach ($parts['objects'] as $object) {
                    foreach ($words as $word) {
                        if (self::sameWord($object, $word)) {
                            ++$covered;
                            break;
                        }
                    }
                }
                $score += $this->options->objectBonus * $covered / count($parts['objects']);
            }
            $ranked[] = [$score, $position++, $name];
        }
        // Best score first; equal scores keep the host's order, so the same
        // query always lists the same tools in the same order.
        usort($ranked, static fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $floor = $ranked[0][0] * $this->options->cut;
        $found = [];
        foreach ($ranked as [$score, , $name]) {
            if ($score < $floor || count($found) >= ($limit ?? $this->options->limit)) {
                break;
            }
            $tool = $this->tools->get($name);
            if ($tool !== null) {
                $found[] = $tool;
            }
        }

        return $found;
    }

    /**
     * The query with the host's synonyms added after the words they stand
     * for, so the original words still count.
     */
    private function expand(string $query): string
    {
        if ($this->options->synonyms === []) {
            return $query;
        }
        $synonyms = array_change_key_case($this->options->synonyms, CASE_LOWER);
        $extra = [];
        foreach ($this->analyzer->words($query) as $word) {
            if (isset($synonyms[$word])) {
                $extra[] = $synonyms[$word];
            }
        }

        return $extra === [] ? $query : $query . ' ' . implode(' ', $extra);
    }

    /**
     * The names and descriptions of the tool's parameters, so a query naming
     * what is given ("by email") finds the tool that takes it.
     */
    private static function parameterText(Tool $tool): string
    {
        $properties = $tool->inputSchema()['properties'] ?? [];
        $text = [];
        foreach (is_array($properties) ? $properties : [] as $name => $schema) {
            $text[] = (string) $name;
            if (is_array($schema) && is_string($schema['description'] ?? null)) {
                $text[] = $schema['description'];
            }
        }

        return implode(' ', $text);
    }

    /**
     * A prefix every name shares, such as "shop_", which tells no tool apart
     * and would hide the verb the name starts with.
     *
     * @param list<string> $names
     */
    private static function commonPrefix(array $names): string
    {
        if (count($names) < 2) {
            return '';
        }
        $first = explode('_', $names[0], 2);
        if (count($first) < 2) {
            return '';
        }
        // A shared first word that is a verb ("get_" on a read-only server) is
        // the verb of every name, not a prefix: keep it.
        if (VerbIntent::isVerb($first[0])) {
            return '';
        }
        $prefix = $first[0] . '_';
        foreach ($names as $name) {
            if (!str_starts_with($name, $prefix)) {
                return '';
            }
        }

        return $prefix;
    }

    private static function stripPrefix(string $name, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
    }

    /**
     * Whether two words are the same but for an English plural ending, for
     * object coverage only: "statuses" names a "status", "categories" a
     * "category". The index itself keeps words exact.
     */
    private static function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        return $long === $short . 's'
            || $long === $short . 'es'
            || (str_ends_with($short, 'y') && $long === substr($short, 0, -1) . 'ies');
    }
}
