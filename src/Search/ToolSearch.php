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
 * lifted, by the pairs of words a new query shares with those searches,
 * within a cap; without one, the same query always gives the same answer.
 */
final class ToolSearch
{
    /**
     * The bytes of each field of a request that are read. A request names a
     * tool in a few words; each word read costs memory for its trigrams, so
     * an unbounded field would let one call use memory out of all proportion.
     */
    private const MAX_FIELD_BYTES = 1024;

    /**
     * The words of a search that are paired for learning. A request names a
     * tool in a few words; the pairs grow with the square of the words, so a
     * long one is cut to keep each search and each record small.
     */
    private const MAX_PAIRED_WORDS = 16;

    /**
     * Starts the key of a whole learned search, its words in alphabetical
     * order after it. The Analyzer never keeps a tab or a space inside a
     * word, so such a key is never taken for a word or a pair.
     */
    private const SEARCH_KEY = "\t";

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
        $tokens = [];
        foreach ($fields as $field => $text) {
            $text = $this->expand(self::bounded($text));
            array_push($tokens, ...$this->analyzer->tokens($text));
            $weight = $this->options->queryWeights[$field] ?? 1.0;
            // The description says what the tool is about; the other fields
            // name it (see SearchOptions::DESCRIPTION_WEIGHTS).
            $fieldScores = $this->index->score($text, $field === 'description');
            if ($fieldScores === []) {
                continue;
            }
            // Tempered by the field's own best match: an object of several
            // words that every neighbour shares outscored the one verb that
            // told them apart, by its size rather than by what it says.
            $weight /= max($fieldScores) ** $this->options->fieldBalance;
            foreach ($fieldScores as $name => $score) {
                // A cast would be undone: PHP keys "123" as an integer anyway, so
                // the names are made strings again once ranked.
                $scores[$name] = ($scores[$name] ?? 0.0) + $weight * $score;
            }
        }
        // The known terms of each token apart: learning pairs terms of
        // different tokens only (see pairs()).
        $groups = [];
        $unmatched = [];
        $learns = $this->learning !== null && $this->options->learningWeight > 0;
        foreach (array_unique($tokens) as $token) {
            $terms = array_values(array_filter(Analyzer::terms($token), fn(string $term): bool => $this->index->has($term)));
            if ($terms !== []) {
                $groups[] = $terms;
            }
            // The caller is told the words it wrote, never a pair of
            // characters cut out of its sentence, which it could not reuse.
            if ($terms === []) {
                $unmatched[] = $token;
                // What is learned is keyed by the tool word closest to it,
                // so a verb written another way still pairs with its object
                // (see ToolIndex::nearest()). Only when learning, since
                // finding it reads every word of the index.
                $nearest = $learns ? $this->index->nearest($token) : null;
                if ($nearest !== null) {
                    $groups[] = [$nearest];
                }
            }
        }
        $searchId = $groups === [] ? null : self::encode($groups);
        if ($scores === []) {
            return new SearchResult([], $searchId, $unmatched);
        }

        $best = max($scores);
        foreach ($this->learned($groups, count(array_unique($tokens))) as $name => $lift) {
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
        $groups = [];
        foreach (self::decode($searchId) as $group) {
            $group = array_values(array_filter($group, fn(string $word): bool => $this->index->has($word)));
            if ($group !== []) {
                $groups[] = $group;
            }
        }
        if ($groups !== []) {
            $this->learning->record([...self::flat($groups), ...self::pairs($groups), self::searchKey($groups)], $tool);
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
            // Whole tokens: a title in Chinese is offered as the caller can
            // write it again, not as the pairs the index keeps.
            foreach (array_unique($this->analyzer->tokens($tool->title())) as $word) {
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
     * A query of two words or more is matched by its pairs of words, not word
     * by word. On a server with many tools about the same thing, a single
     * word ("certificate") learned from one search ("get the certificate")
     * would lift that tool for every other search naming the thing ("delete
     * the certificate"); a pair is only shared by searches that agree on
     * more than the thing. A query of one word has no pair and uses the
     * single words.
     *
     * The mean lift is then multiplied by the share of the query's pairs
     * that reached the tool at all, so a search that agrees on one pair out
     * of three lifts it a ninth as much as the same search asked again.
     *
     * A query that holds one word the tools use among words they do not
     * ("sign | agreement | lot") has no pair either, but it is not a query
     * of one word: what it asks beyond the thing is only unknown. Its word
     * alone would lift every tool learned for the thing ("show the history
     * of a lot"), so it lifts only a tool one of whose learned searches it
     * holds whole.
     *
     * @param list<list<string>> $groups the known terms of each token
     * @param int $tokens how many distinct tokens the query wrote, known or not
     *
     * @return array<string, float>
     */
    private function learned(array $groups, int $tokens): array
    {
        $words = self::flat($groups);
        if ($this->learning === null || $this->options->learningWeight <= 0 || $words === []) {
            return [];
        }
        $counts = $this->learning->load();
        $lift = [];
        $alone = count($words) === 1 && $tokens > 1;
        if (count($words) > 1) {
            $words = self::pairs($groups);
        }
        $hits = [];
        foreach ($words as $word) {
            foreach ($counts[$word] ?? [] as $tool => $runs) {
                $lift[$tool] = ($lift[$tool] ?? 0.0) + min($runs, $this->options->learningCap) / $this->options->learningCap;
                $hits[$tool] = ($hits[$tool] ?? 0) + 1;
            }
        }
        $total = count($words);
        $coverage = $lift === [] ? [] : self::coverage($counts, self::flat($groups), $lift);
        foreach ($lift as $tool => $sum) {
            // Scaled again by the share of the pairs that hit the tool: a
            // search learned for a neighbour ("add a site address") shares
            // one pair with a new one ("change a site address") and should
            // barely move it, while the same request asked again hits every
            // pair and keeps its whole lift.
            $lift[$tool] = $sum / $total * ($hits[$tool] / $total);
            // And by the square of the share of a learned search's words the
            // query holds: "view the instruments to lend" taught the list
            // the pair "instrument lend", which "lend an instrument" holds
            // whole, but the view it does not. A tool learned before whole
            // searches were kept has none, and keeps its lift.
            $lift[$tool] *= ($coverage[$tool] ?? 1.0) ** 2;
            if ($alone && ($coverage[$tool] ?? 1.0) < 1.0) {
                unset($lift[$tool]);
            }
        }

        return $lift;
    }

    /**
     * For each tool lifted, the largest share of the words of one search
     * learned for it that the query holds.
     *
     * The whole searches are only found by reading every key of the store,
     * as load() has already read them; a key that is not a search, or a
     * search of no lifted tool, is passed over before it is cut.
     *
     * @param array<array-key, array<string, int>> $counts array-key, not
     *        string: the library never learns a number alone, but a store
     *        written by the host may hand one back, and PHP holds a key
     *        such as "123" as an integer
     * @param list<string> $words the terms of the query
     * @param array<string, float> $lift
     *
     * @return array<string, float>
     */
    private static function coverage(array $counts, array $words, array $lift): array
    {
        $query = array_flip($words);
        $coverage = [];
        foreach ($counts as $key => $tools) {
            // Cast back before str_starts_with(), which strict types would
            // refuse an integer key.
            $key = (string) $key;
            if (!str_starts_with($key, self::SEARCH_KEY) || array_intersect_key($tools, $lift) === []) {
                continue;
            }
            $learned = explode(' ', substr($key, strlen(self::SEARCH_KEY)));
            $held = count(array_intersect_key(array_flip($learned), $query)) / count($learned);
            foreach (array_keys(array_intersect_key($tools, $lift)) as $tool) {
                $coverage[$tool] = max($coverage[$tool] ?? 0.0, $held);
            }
        }

        return $coverage;
    }

    /**
     * The key under which a whole search is learned: its words, cut as for
     * pairs, in alphabetical order.
     *
     * @param list<list<string>> $groups
     */
    private static function searchKey(array $groups): string
    {
        $words = array_values(array_unique(self::flat($groups)));
        sort($words);

        return self::SEARCH_KEY . implode(' ', array_slice($words, 0, self::MAX_PAIRED_WORDS));
    }

    /**
     * Each pair of distinct words as one key, "a b" with a before b. The
     * Analyzer never keeps a space inside a word, so a pair cannot be taken
     * for a word, and the store keeps both under its one format.
     *
     * When the request has several tokens, two terms of the same token are
     * not paired. A token of Japanese, Chinese or Korean gives several pairs
     * of characters, all of one word: "飼い主" (owner) gives "飼い" and
     * "い主", and their pair says the object again, as one word would.
     * Paired, they lifted the tool that lists owners for every request about
     * an owner, which is what pairs are there to avoid; the verb and the
     * object are two tokens, and their pairs still are. A request of one
     * token (a sentence without spaces) has nothing else to pair, and keeps
     * every pair of its terms. A token of an alphabet gives one term, so
     * nothing changes for it.
     *
     * @param list<list<string>> $groups the known terms of each token
     *
     * @return list<string>
     */
    private static function pairs(array $groups): array
    {
        $words = self::flat($groups);
        sort($words);
        $words = array_slice($words, 0, self::MAX_PAIRED_WORDS);
        $tokensOf = [];
        foreach ($groups as $token => $group) {
            foreach ($group as $word) {
                $tokensOf[$word][$token] = true;
            }
        }
        $apart = count($groups) > 1;
        $pairs = [];
        foreach ($words as $i => $first) {
            foreach (array_slice($words, $i + 1) as $second) {
                if ($apart && array_intersect_key($tokensOf[$first] ?? [], $tokensOf[$second] ?? []) !== []) {
                    continue;
                }
                $pairs[] = $first . ' ' . $second;
            }
        }

        return $pairs;
    }

    /**
     * @param list<list<string>> $groups
     *
     * @return list<string>
     */
    private static function flat(array $groups): array
    {
        return array_values(array_unique(array_merge(...$groups)));
    }

    /**
     * @param array<string, array<string, string>> $documents
     */
    private static function index(array $documents, SearchOptions $options, Analyzer $analyzer, ?IndexCache $cache): ToolIndex
    {
        // Without a cache nothing is kept for the next request: what finding
        // the words alike a word reads is then built only if a search meets
        // a word the tools lack.
        if ($cache === null) {
            return ToolIndex::build($documents, $options->weights, $analyzer, $options->trigramWeight, false, $options->descriptionWeights);
        }
        // The key covers every text indexed, so it changes with any tool and
        // differs between callers who see different tools.
        $key = hash('xxh128', serialize($documents) . $options->indexKey());
        $saved = $cache->get($key);
        $index = $saved === null ? null : ToolIndex::fromArray($saved, $analyzer);
        // The key is a hash of the tools, not the tools: an index saved for
        // others, through a collision or a host cache that mixes its keys,
        // would hold the words of tools this caller does not see. Only an
        // index of exactly the tools searched is used.
        if ($index !== null && $index->ids() !== array_keys($documents)) {
            $index = null;
        }
        if ($index === null) {
            // With what alike() reads: any search meeting a word the tools lack
            // needs it, and working it out again at each request cost more
            // than the search itself.
            $index = ToolIndex::build($documents, $options->weights, $analyzer, $options->trigramWeight, true, $options->descriptionWeights);
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
        // Keyed by the token each stands for (see SearchOptions).
        $synonyms = $this->options->synonyms;
        $extra = [];
        // The pairs too: in a sentence without spaces the host's word is
        // never a token of its own, only a pair inside one.
        foreach ([...$this->analyzer->tokens($text), ...$this->analyzer->words($text)] as $word) {
            if (isset($synonyms[$word])) {
                $extra[$word] = $synonyms[$word];
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
     * The tokens apart by spaces, the terms of one token joined by "+",
     * which the Analyzer never keeps in a term. An id of single words, as
     * ids were before tokens were told apart, reads as one token per word.
     *
     * @param list<list<string>> $groups
     */
    private static function encode(array $groups): string
    {
        $tokens = [];
        foreach ($groups as $group) {
            sort($group);
            $tokens[] = implode('+', $group);
        }
        // Sorted, so the same words give the same id in any order.
        sort($tokens);

        return rtrim(strtr(base64_encode(implode(' ', $tokens)), '+/', '-_'), '=');
    }

    /**
     * @return list<list<string>> at most 64 terms in all
     */
    private static function decode(string $searchId): array
    {
        $text = base64_decode(strtr(substr($searchId, 0, 2048), '-_', '+/'), true);
        if (!is_string($text)) {
            return [];
        }
        $groups = [];
        $left = 64;
        foreach (explode(' ', $text) as $token) {
            $group = array_slice(array_values(array_filter(explode('+', $token), static fn(string $word): bool => $word !== '')), 0, $left);
            if ($group !== []) {
                $groups[] = $group;
                $left -= count($group);
            }
            if ($left <= 0) {
                break;
            }
        }

        return $groups;
    }
}
