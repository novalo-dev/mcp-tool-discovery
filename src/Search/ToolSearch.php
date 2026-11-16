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

use Novalo\McpToolDiscovery\Search\Cache\IndexCache;
use Novalo\McpToolDiscovery\Search\Learning\LearningStore;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * Finds the tools that answer a query, the best first.
 *
 * Each field of the query is scored on its own with BM25F over the name,
 * title, description and parameters of each tool, then the scores are added
 * with the weight of each field. Nothing is parsed or assumed: no list of
 * verbs, no stop words, no naming convention. A word every tool carries (a
 * shared prefix, a common verb) weighs almost nothing by itself, which is
 * what BM25 is for.
 *
 * With a learning store, the tools run with success after past searches are
 * lifted, word by word, within a cap; without one, the same query always
 * gives the same answer.
 */
final class ToolSearch
{
    /**
     * The bytes of each field of a request that are read. A request names a
     * tool in a few words; each word read costs memory for its trigrams, so
     * an unbounded field would let one call use memory out of all proportion.
     */
    private const MAX_FIELD_BYTES = 1024;

    private readonly ToolIndex $index;
    private readonly Analyzer $analyzer;

    /** @var array<string, int> */
    private readonly array $position;

    public function __construct(
        private readonly ToolSet $tools,
        private readonly SearchOptions $options = new SearchOptions(),
        ?IndexCache $cache = null,
        private readonly ?LearningStore $learning = null,
    ) {
        $this->analyzer = new Analyzer($options->stopWords);
        $documents = [];
        foreach ($tools as $name => $tool) {
            $documents[$name] = [
                'name' => $tool->name,
                'title' => $tool->title(),
                'description' => $tool->description(),
                'parameters' => self::parameterText($tool),
            ];
        }
        $this->position = array_flip(array_keys($documents));
        $this->index = self::index($documents, $options, $this->analyzer, $cache);
    }

    /**
     * @return list<Tool> best first; empty when nothing matches
     */
    public function search(string|SearchQuery $query, ?int $limit = null): array
    {
        return $this->find(is_string($query) ? SearchQuery::text($query) : $query, $limit)->tools;
    }

    public function find(SearchQuery $query, ?int $limit = null): SearchResult
    {
        $fields = $query->fields();
        $scores = [];
        $words = [];
        foreach ($fields as $field => $text) {
            $text = $this->expand(self::bounded($text));
            array_push($words, ...$this->analyzer->words($text));
            $weight = $this->options->queryWeights[$field] ?? 1.0;
            foreach ($this->index->score($text) as $name => $score) {
                // A cast would be undone: PHP keys "123" as an integer anyway, so
                // the names are made strings again once ranked.
                $scores[$name] = ($scores[$name] ?? 0.0) + $weight * $score;
            }
        }
        $words = array_values(array_unique($words));
        $known = array_values(array_filter($words, fn(string $word): bool => $this->index->has($word)));
        $unmatched = array_values(array_diff($words, $known));
        $searchId = $known === [] ? null : self::encode($known);
        if ($scores === []) {
            return new SearchResult([], $searchId, $unmatched);
        }

        $best = max($scores);
        foreach ($this->learned($known) as $name => $lift) {
            // Only tools the text already found: what was learned reorders
            // the answer, it never brings a tool the query does not describe.
            if (isset($scores[$name])) {
                $scores[$name] += $this->options->learningWeight * $best * $lift;
            }
        }
        $ranked = [];
        foreach ($scores as $name => $score) {
            // Cast back: PHP keys a tool named "123" as an integer.
            $ranked[] = [$score, $this->position[$name] ?? PHP_INT_MAX, (string) $name];
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

        return new SearchResult($found, $searchId, $unmatched);
    }

    /**
     * Records that a tool answered a search, when a learning store is set.
     *
     * The search id only carries words the tools use, and they are checked
     * again here, as is the tool: an id made up by the caller can at most
     * lift a visible tool for words it already holds, within the cap.
     */
    public function learn(string $searchId, string $tool): void
    {
        if ($this->learning === null || $this->options->learningWeight <= 0 || !$this->tools->has($tool)) {
            return;
        }
        $words = array_values(array_filter(self::decode($searchId), fn(string $word): bool => $this->index->has($word)));
        if ($words !== []) {
            $this->learning->record($words, $tool);
        }
    }

    /**
     * The words the tool titles use most, for a caller whose query found
     * nothing: the words of this server, computed from its own tools.
     *
     * @return list<string>
     */
    public function vocabulary(int $size = 30): array
    {
        $count = [];
        foreach ($this->tools as $tool) {
            foreach (array_unique($this->analyzer->words($tool->title())) as $word) {
                $count[$word] = ($count[$word] ?? 0) + 1;
            }
        }
        $total = count($this->tools->names());
        // A word in most titles (a brand, a prefix) tells no tool apart.
        $count = array_filter($count, static fn(int $tools): bool => $total < 4 || $tools <= $total / 2);
        uksort($count, static fn(string $a, string $b): int => [$count[$b], $a] <=> [$count[$a], $b]);

        return array_map('strval', array_slice(array_keys($count), 0, $size));
    }

    /**
     * How far what was learned lifts each tool for these words, from 0 to 1.
     *
     * @param list<string> $words
     *
     * @return array<string, float>
     */
    private function learned(array $words): array
    {
        if ($this->learning === null || $this->options->learningWeight <= 0 || $words === []) {
            return [];
        }
        $counts = $this->learning->load();
        $lift = [];
        foreach ($words as $word) {
            foreach ($counts[$word] ?? [] as $tool => $runs) {
                $lift[$tool] = ($lift[$tool] ?? 0.0) + min($runs, $this->options->learningCap) / $this->options->learningCap;
            }
        }

        return array_map(static fn(float $sum): float => $sum / count($words), $lift);
    }

    /**
     * @param array<string, array<string, string>> $documents
     */
    private static function index(array $documents, SearchOptions $options, Analyzer $analyzer, ?IndexCache $cache): ToolIndex
    {
        if ($cache === null) {
            return ToolIndex::build($documents, $options->weights, $analyzer, $options->trigramWeight);
        }
        // The key covers every text indexed, so it changes with any tool and
        // differs between callers who see different tools.
        $key = hash('xxh128', serialize($documents) . $options->indexKey());
        $saved = $cache->get($key);
        $index = $saved === null ? null : ToolIndex::fromArray($saved, $analyzer);
        if ($index === null) {
            $index = ToolIndex::build($documents, $options->weights, $analyzer, $options->trigramWeight);
            $cache->set($key, $index->toArray());
        }

        return $index;
    }

    /**
     * The field cut to its first MAX_FIELD_BYTES bytes.
     */
    private static function bounded(string $text): string
    {
        if (strlen($text) <= self::MAX_FIELD_BYTES) {
            return $text;
        }
        // Cut on a character, not inside one: a broken last character would
        // make the whole field invalid UTF-8 for a server without mbstring.
        $cut = substr($text, 0, self::MAX_FIELD_BYTES);
        $clean = preg_replace('/[\xC0-\xFF][\x80-\xBF]*\z/', '', $cut);

        return is_string($clean) ? $clean : $cut;
    }

    /**
     * The query with the host's synonyms added after the words they stand
     * for, so the original words still count.
     */
    private function expand(string $text): string
    {
        if ($this->options->synonyms === []) {
            return $text;
        }
        $synonyms = array_change_key_case($this->options->synonyms, CASE_LOWER);
        $extra = [];
        foreach ($this->analyzer->words($text) as $word) {
            if (isset($synonyms[$word])) {
                $extra[] = $synonyms[$word];
            }
        }

        return $extra === [] ? $text : $text . ' ' . implode(' ', $extra);
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
     * @param list<string> $words
     */
    private static function encode(array $words): string
    {
        sort($words);

        return rtrim(strtr(base64_encode(implode(' ', $words)), '+/', '-_'), '=');
    }

    /**
     * @return list<string>
     */
    private static function decode(string $searchId): array
    {
        $text = base64_decode(strtr(substr($searchId, 0, 2048), '-_', '+/'), true);

        return is_string($text) ? array_slice(array_values(array_filter(explode(' ', $text), static fn(string $word): bool => $word !== '')), 0, 64) : [];
    }
}
