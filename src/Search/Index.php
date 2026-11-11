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

/**
 * A BM25F index over documents made of weighted fields, held in memory.
 *
 * BM25F rather than plain BM25: each field has a weight, so a word in a tool's
 * name counts more than the same word in its description, and the length of
 * each field is normalised on its own. A rare word weighs more than a common
 * one ("refund" against "order"), and repeating a word earns less each time.
 *
 * Built per request from a few hundred documents in well under a millisecond
 * each, so there is no file, no lock and nothing to rebuild when the host's
 * list changes. Know nothing of tools: any document with text fields fits.
 */
final class Index
{
    private const K1 = 1.2;
    private const B = 0.75;

    /**
     * @param array<int|string, array<string, array{0: array<array-key, int>, 1: int, 2: array<array-key, int>, 3: int}>> $documents
     *        per document, per field: word counts, word total, trigram counts, trigram total
     * @param array<string, float> $weights
     * @param array<string, array{0: float, 1: float}> $averages per field: average word and trigram lengths
     * @param array<array-key, int> $wordFrequency documents holding each word
     * @param array<array-key, int> $gramFrequency documents holding each trigram
     *
     * Keys are array-key, not string: PHP turns a key such as "123", a trigram
     * of a word with digits, into an integer, and looks it up the same way.
     */
    private function __construct(
        private readonly Analyzer $analyzer,
        private readonly array $documents,
        private readonly array $weights,
        private readonly array $averages,
        private readonly array $wordFrequency,
        private readonly array $gramFrequency,
        private readonly float $trigramWeight,
    ) {}

    /**
     * @param iterable<int|string, array<string, string>> $documents id => field => text
     * @param array<string, float> $weights field => weight; a field missing here is not indexed
     * @param float $trigramWeight how much trigram matches count against whole-word matches
     */
    public static function build(iterable $documents, array $weights, Analyzer $analyzer = new Analyzer(), float $trigramWeight = 0.35): self
    {
        $indexed = [];
        $wordFrequency = [];
        $gramFrequency = [];
        $totals = [];
        foreach ($documents as $id => $fields) {
            $seenWords = [];
            $seenGrams = [];
            foreach ($weights as $field => $weight) {
                $words = $analyzer->words($fields[$field] ?? '');
                $grams = [];
                foreach ($words as $word) {
                    array_push($grams, ...Analyzer::trigrams($word));
                }
                $indexed[$id][$field] = [array_count_values($words), count($words), array_count_values($grams), count($grams)];
                $totals[$field][0] = ($totals[$field][0] ?? 0) + count($words);
                $totals[$field][1] = ($totals[$field][1] ?? 0) + count($grams);
                $seenWords += array_fill_keys($words, true);
                $seenGrams += array_fill_keys($grams, true);
            }
            foreach (array_keys($seenWords) as $word) {
                $wordFrequency[$word] = ($wordFrequency[$word] ?? 0) + 1;
            }
            foreach (array_keys($seenGrams) as $gram) {
                $gramFrequency[$gram] = ($gramFrequency[$gram] ?? 0) + 1;
            }
        }
        $count = max(1, count($indexed));
        $averages = [];
        foreach ($weights as $field => $weight) {
            $averages[$field] = [($totals[$field][0] ?? 0) / $count, ($totals[$field][1] ?? 0) / $count];
        }

        return new self($analyzer, $indexed, $weights, $averages, $wordFrequency, $gramFrequency, $trigramWeight);
    }

    /**
     * Every document that matches at least one term, with its score.
     *
     * @return array<int|string, float> id => score, in index order
     */
    public function score(string $query): array
    {
        $words = $this->analyzer->words($query);
        if ($words === []) {
            return [];
        }
        $grams = [];
        foreach ($words as $word) {
            array_push($grams, ...Analyzer::trigrams($word));
        }
        $words = array_values(array_unique($words));
        $grams = array_values(array_unique($grams));

        $scores = [];
        foreach ($this->documents as $id => $fields) {
            $score = $this->bm25f($fields, $words, 0, $this->wordFrequency)
                // Divided by the number of query words, so a long query does
                // not let its many trigrams outweigh the words themselves.
                + $this->trigramWeight * $this->bm25f($fields, $grams, 2, $this->gramFrequency) / count($words);
            if ($score > 0) {
                $scores[$id] = $score;
            }
        }

        return $scores;
    }

    /**
     * @param array<string, array{0: array<array-key, int>, 1: int, 2: array<array-key, int>, 3: int}> $fields
     * @param list<string> $terms
     * @param 0|2 $slot 0 for words, 2 for trigrams
     * @param array<array-key, int> $frequency
     */
    private function bm25f(array $fields, array $terms, int $slot, array $frequency): float
    {
        $documents = count($this->documents);
        $score = 0.0;
        foreach ($terms as $term) {
            if (!isset($frequency[$term])) {
                continue;
            }
            $weighted = 0.0;
            foreach ($this->weights as $field => $weight) {
                $occurrences = $fields[$field][$slot][$term] ?? 0;
                if ($occurrences === 0) {
                    continue;
                }
                $average = $this->averages[$field][$slot === 0 ? 0 : 1] ?: 1.0;
                $length = $fields[$field][$slot + 1];
                $weighted += $weight * $occurrences / (1 - self::B + self::B * $length / $average);
            }
            if ($weighted > 0) {
                $idf = log(1 + ($documents - $frequency[$term] + 0.5) / ($frequency[$term] + 0.5));
                $score += $idf * $weighted * (self::K1 + 1) / ($weighted + self::K1);
            }
        }

        return $score;
    }
}
