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
 * one ("rollback" against "file"), and repeating a word earns less each time.
 *
 * Inverted: each term points to the documents that hold it, with its
 * weighted frequency there already worked out, so a search only visits the
 * documents that share a term with the query. Visiting every document for
 * every term was most of the cost of a search once the index is cached.
 *
 * Building it is the cost of a search, so it can be saved as a plain array
 * (toArray) and restored (fromArray) by a cache. It knows nothing of tools:
 * any document with text fields fits.
 *
 * Not named Index: a host that drops an index.php in every folder of its
 * package, as some plugin stores require, would put it beside Index.php, and
 * on a filesystem that ignores case one of the two overwrites the other.
 */
final class ToolIndex
{
    private const K1 = 1.2;
    private const B = 0.75;

    /**
     * Raised whenever the saved shape or the way text is cut into terms
     * changes, so an old cache entry is rebuilt rather than compared with
     * terms it never held.
     */
    private const FORMAT = 5;

    /**
     * @param list<int|string> $ids the documents, in index order
     * @param array<array-key, array<int|string, float>> $words per word, per document holding it: its
     *        weighted frequency, the fields' weights and lengths already applied
     * @param array<array-key, array<int|string, float>> $grams the same for trigrams
     *
     * Keys are array-key, not string: PHP turns a key such as "123", a trigram
     * of a word with digits, into an integer, and looks it up the same way.
     */
    private function __construct(
        private readonly Analyzer $analyzer,
        private readonly array $ids,
        private readonly array $words,
        private readonly array $grams,
        private readonly float $trigramWeight,
    ) {}

    /**
     * @param iterable<int|string, array<string, string>> $documents id => field => text
     * @param array<string, float> $weights field => weight; a field missing here is not indexed
     * @param float $trigramWeight how much trigram matches count against whole-word matches
     */
    public static function build(iterable $documents, array $weights, Analyzer $analyzer = new Analyzer(), float $trigramWeight = 0.6): self
    {
        $counts = [];
        $totals = [];
        $trigrams = [];
        foreach ($documents as $id => $fields) {
            foreach ($weights as $field => $weight) {
                $fieldWords = $analyzer->words($fields[$field] ?? '');
                $fieldGrams = [];
                foreach ($fieldWords as $word) {
                    // Tools of one server share most of their words: each
                    // word is cut into trigrams once for the whole build.
                    array_push($fieldGrams, ...($trigrams[$word] ??= Analyzer::trigrams($word)));
                }
                $counts[$id][$field] = [array_count_values($fieldWords), count($fieldWords), array_count_values($fieldGrams), count($fieldGrams)];
                $totals[$field][0] = ($totals[$field][0] ?? 0) + count($fieldWords);
                $totals[$field][1] = ($totals[$field][1] ?? 0) + count($fieldGrams);
            }
        }
        $count = max(1, count($counts));
        $words = [];
        $grams = [];
        // A term's weighted frequency in a document does not depend on the
        // query, so it is worked out once here rather than at every search.
        foreach ($counts as $id => $fields) {
            foreach ($weights as $field => $weight) {
                [$fieldWords, $wordLength, $fieldGrams, $gramLength] = $fields[$field];
                $wordNorm = self::norm($wordLength, ($totals[$field][0] ?? 0) / $count);
                foreach ($fieldWords as $word => $occurrences) {
                    $words[$word][$id] = ($words[$word][$id] ?? 0.0) + $weight * $occurrences / $wordNorm;
                }
                $gramNorm = self::norm($gramLength, ($totals[$field][1] ?? 0) / $count);
                foreach ($fieldGrams as $gram => $occurrences) {
                    $grams[$gram][$id] = ($grams[$gram][$id] ?? 0.0) + $weight * $occurrences / $gramNorm;
                }
            }
        }

        return new self($analyzer, array_keys($counts), $words, $grams, $trigramWeight);
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
        $wordScores = $this->bm25f($words, $this->words);
        $gramScores = $this->bm25f(array_values(array_unique($grams)), $this->grams);

        $scores = [];
        foreach ($this->ids as $id) {
            $score = ($wordScores[$id] ?? 0.0)
                // Divided by the number of query words, so a long query does
                // not let its many trigrams outweigh the words themselves.
                + $this->trigramWeight * ($gramScores[$id] ?? 0.0) / count($words);
            if ($score > 0) {
                $scores[$id] = $score;
            }
        }

        return $scores;
    }

    /**
     * The BM25F score of each document holding at least one of the terms.
     *
     * Term at a time, in the order of the query, so each document adds up
     * its terms in the same order whatever documents are around it.
     *
     * @param list<string> $terms
     * @param array<array-key, array<int|string, float>> $postings
     *
     * @return array<int|string, float>
     */
    private function bm25f(array $terms, array $postings): array
    {
        $documents = count($this->ids);
        $scores = [];
        foreach ($terms as $term) {
            if (!isset($postings[$term])) {
                continue;
            }
            $frequency = count($postings[$term]);
            $idf = log(1 + ($documents - $frequency + 0.5) / ($frequency + 0.5));
            foreach ($postings[$term] as $id => $weighted) {
                if ($weighted > 0) {
                    $scores[$id] = ($scores[$id] ?? 0.0) + $idf * $weighted * (self::K1 + 1) / ($weighted + self::K1);
                }
            }
        }

        return $scores;
    }

    /**
     * How much a field's length tempers a match in it, against the average.
     */
    private static function norm(int $length, float $average): float
    {
        return 1 - self::B + self::B * $length / ($average > 0 ? $average : 1.0);
    }

    /**
     * Whether a word, as the Analyzer cuts it, appears in any document.
     */
    public function has(string $word): bool
    {
        return isset($this->words[$word]);
    }

    /**
     * The ids of the documents, in index order.
     *
     * @return list<int|string>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * The index as a plain array of scalars, for a cache to keep.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'ids' => $this->ids,
            'words' => $this->words,
            'grams' => $this->grams,
            'trigramWeight' => $this->trigramWeight,
        ];
    }

    /**
     * An index saved by toArray(), or null when the array is not one, so the
     * caller builds it again rather than searching a broken index.
     *
     * @param array<array-key, mixed> $saved
     */
    public static function fromArray(array $saved, Analyzer $analyzer = new Analyzer()): ?self
    {
        if (($saved['format'] ?? null) !== self::FORMAT) {
            return null;
        }
        foreach (['ids', 'words', 'grams'] as $key) {
            if (!is_array($saved[$key] ?? null)) {
                return null;
            }
        }
        if (!is_float($saved['trigramWeight'] ?? null) && !is_int($saved['trigramWeight'] ?? null)) {
            return null;
        }
        /** @var list<int|string> $ids */
        $ids = $saved['ids'];
        /** @var array<array-key, array<int|string, float>> $words */
        $words = $saved['words'];
        /** @var array<array-key, array<int|string, float>> $grams */
        $grams = $saved['grams'];

        return new self($analyzer, $ids, $words, $grams, (float) $saved['trigramWeight']);
    }
}
