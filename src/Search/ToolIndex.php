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
    private const FORMAT = 10;

    /**
     * How alike a word the tools do not use and a word they do must be to be
     * taken for it: the Dice coefficient of their trigrams. Chosen on the
     * train half; see nearest() and alike().
     */
    private const NEAREST = 0.3;

    /**
     * How much a word the tools use counts, against the word itself, when a
     * query holds a word alike it instead (see score()). Chosen on the train
     * half: from 0.25 to 1 the requests in fields barely move, as one
     * sentence they gain the most around 0.5 to 0.75.
     */
    private const ALIKE_WEIGHT = 0.5;

    /**
     * How many indexed words alike one query word are kept, the most alike
     * first. Each is scored on its own, so without a bound the cost of a
     * query grows with how many words of the index share a few trigrams,
     * which a large host can hold by the thousand. No request of the
     * benchmark meets more than 24.
     */
    private const MAX_ALIKE = 32;

    /**
     * How many words of one query no tool uses are looked up in full, the
     * first ones written. Finding the words alike one visits every word of
     * the index sharing a trigram with it, so a field of a thousand unknown
     * words would cost a thousand of those visits; past this many, the
     * words still count by their trigrams. No request of the benchmark
     * holds more than 18.
     */
    private const MAX_UNKNOWN = 32;

    /**
     * The words of the index in index order, built the first time a query
     * holds a word no document does.
     *
     * @var list<string>|null
     */
    private ?array $wordList = null;

    /**
     * The indexed words holding a Latin letter outside ASCII, cut into
     * characters and grouped by how many they have, built the first time a
     * query holds a word no document does; see unmarked().
     *
     * @var array<int, list<array{string, list<string>}>>|null
     */
    private ?array $marked = null;

    /**
     * The same for the indexed words written in ASCII only.
     *
     * @var array<int, list<array{string, list<string>}>>|null
     */
    private ?array $plain = null;

    /**
     * @param list<int|string> $ids the documents, in index order
     * @param array<array-key, array<int|string, float>> $words per word, per document holding it: its
     *        weighted frequency, the fields' weights and lengths already applied
     * @param array<array-key, array<int|string, float>> $grams the same for trigrams
     * @param array{array<array-key, list<int>>, list<int>}|null $nearby see invert(); null until alike() needs it
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
        private ?array $nearby = null,
    ) {}

    /**
     * @param iterable<int|string, array<string, string>> $documents id => field => text
     * @param array<string, float> $weights field => weight; a field missing here is not indexed
     * @param float $trigramWeight how much trigram matches count against whole-word matches
     * @param bool $nearby whether what alike() reads is built now and kept with the index, rather than at
     *        the first query holding a word no document does
     */
    public static function build(iterable $documents, array $weights, Analyzer $analyzer = new Analyzer(), float $trigramWeight = 0.6, bool $nearby = false): self
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

        // Built here, from the trigrams already cut, and kept by the cache:
        // working them out again at each request cost more than the search
        // itself.
        $inverted = $nearby ? self::invert($words, $trigrams) : null;

        return new self($analyzer, array_keys($counts), $words, $grams, $trigramWeight, $inverted);
    }

    /**
     * The trigrams of each word, inverted: per trigram, the position of each
     * word holding it, and per position, how many distinct trigrams the word
     * has. Positions follow the index order of the words.
     *
     * @param array<array-key, mixed> $words
     * @param array<array-key, list<string>> $trigrams the trigrams already cut, per word
     *
     * @return array{array<array-key, list<int>>, list<int>}
     */
    private static function invert(array $words, array $trigrams = []): array
    {
        $wordsByGram = [];
        $gramCounts = [];
        foreach (array_keys($words) as $position => $word) {
            $wordGrams = array_unique($trigrams[$word] ?? Analyzer::trigrams((string) $word));
            $gramCounts[] = count($wordGrams);
            foreach ($wordGrams as $gram) {
                $wordsByGram[$gram][] = $position;
            }
        }

        return [$wordsByGram, $gramCounts];
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
        // A word no tool uses ("reservation") still reaches the tools by its
        // trigrams, but pooled with the others, trigrams find any word with
        // a common piece ("service" by "ser", "vic"). The words alike it as
        // a whole ("reserve", "reservations") also count as words, by how
        // alike they are, so the tool that holds one is told from the tools
        // that only share pieces of it. Added to the trigrams, not in place
        // of them: alone they lost the requests a trigram still found.
        $unknown = array_slice(array_values(array_filter($words, fn(string $word): bool => !isset($this->words[$word]))), 0, self::MAX_UNKNOWN);
        foreach ($unknown as $word) {
            // A word typed without its marks ("hoc phi" for "học phí") is
            // taken for the tool word it spells, as alike as can be.
            $matches = $this->alike($word);
            foreach ($this->unmarked($word) as $spelled) {
                $matches[$spelled] = 1.0;
            }
            foreach ($matches as $alike => $dice) {
                foreach ($this->bm25f([(string) $alike], $this->words) as $id => $score) {
                    $wordScores[$id] = ($wordScores[$id] ?? 0.0) + self::ALIKE_WEIGHT * $dice * $score;
                }
            }
        }

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
     * The indexed word most alike a word no document holds, by their
     * trigrams, or null when none is alike enough.
     *
     * Learning keeps only the words the tools use, but the word that tells
     * two neighbours apart is often the one a request writes differently:
     * the verb ("أرسل", send!, where the tool says "يرسل", it sends), a
     * conjugation ("prêter" against "prêt"). It found the tool by its
     * trigrams, yet was left out of what was learned, so a search only taught
     * the object it names, which the neighbours share, and lifted them for
     * the next request about that object. Taking the tool word it is closest
     * to keeps the store within the tools' vocabulary and pairs the object
     * with what is done to it again. Ties keep the first word indexed.
     *
     * Only the words sharing a trigram with it are visited, through the
     * inverted trigrams built with the index: a word sharing none is not
     * alike at all.
     */
    public function nearest(string $word): ?string
    {
        $nearest = null;
        $best = 0.0;
        // The lower position on a tie, whatever order the trigrams of the
        // word visited them in. A pass rather than alike(): learning asks
        // for every word a request leaves unmatched, and sorting what alike()
        // sorts cost several times the pass on a large index.
        foreach ($this->dices($word) as $position => $dice) {
            if ($dice > $best || ($dice === $best && $nearest !== null && $position < $nearest)) {
                $nearest = $position;
                $best = $dice;
            }
        }

        return $nearest === null ? null : $this->wordList[$nearest] ?? null;
    }

    /**
     * The indexed words a word spells but for its marks: as long, the same
     * letter wherever both are in ASCII or both outside it, and wherever
     * one is a Latin letter outside ASCII ("ệ", "đ", "ü"), any letter of
     * ASCII in the other. Either side may be the one without its marks.
     *
     * Vietnamese, and many languages written in Latin letters, are often
     * typed without their marks, on a phone above all. Trigrams do not see
     * it: the marks sit in short words, so most trigrams of "hoc" differ
     * from those of "học". Folding a letter to its base needs a table of
     * letters or the intl extension; reading the place of the marks needs
     * neither, only the Unicode script of a character, which PCRE knows.
     * A letter outside the Latin script is never one of these places, so
     * Greek, Cyrillic or Arabic words never match a word of ASCII.
     *
     * The letters that match outright must be at least as many as those
     * taken on trust, so "đơn" is not spelled by any three letters ending
     * in "n". At most MAX_ALIKE words, the first in index order.
     *
     * @return list<string>
     */
    private function unmarked(string $word): array
    {
        $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return [];
        }
        // A word in ASCII can only be spelled by a word with marks; a word
        // with marks by a word with or without them.
        $hasMarks = preg_match('/(?![a-z])\p{Latin}/u', $word) === 1;
        if (!$hasMarks && preg_match('/[^\x00-\x7F]/', $word) === 1) {
            return [];
        }
        // Built from the index at the first word that needs them, each with
        // one pass of PCRE over every word: an index read back from the
        // cache at each request pays for them only when a request holds a
        // word no tool uses, and for the words of ASCII only when it holds
        // one with marks.
        $this->wordList ??= array_map('strval', array_keys($this->words));
        if ($this->marked === null) {
            $this->marked = [];
            /** @var array<int, string>|false $withMarks */
            $withMarks = preg_grep('/(?![a-z])\p{Latin}/u', $this->wordList);
            foreach ($withMarks === false ? [] : $withMarks as $indexed) {
                $letters = preg_split('//u', $indexed, -1, PREG_SPLIT_NO_EMPTY);
                if ($letters !== false) {
                    $this->marked[count($letters)][] = [$indexed, $letters];
                }
            }
        }
        if ($hasMarks && $this->plain === null) {
            $this->plain = [];
            /** @var array<int, string>|false $ascii */
            $ascii = preg_grep('/^[a-z]+$/', $this->wordList);
            foreach ($ascii === false ? [] : $ascii as $indexed) {
                $this->plain[strlen($indexed)][] = [$indexed, str_split($indexed)];
            }
        }
        $candidates = array_merge($hasMarks ? $this->plain[count($characters)] ?? [] : [], $this->marked[count($characters)] ?? []);
        $spelled = [];
        foreach ($candidates as [$indexed, $letters]) {
            if ($indexed === $word) {
                continue;
            }
            $same = 0;
            $trusted = 0;
            foreach ($characters as $position => $character) {
                $letter = $letters[$position];
                if ($character === $letter) {
                    ++$same;
                } elseif (self::mark($character, $letter) || self::mark($letter, $character)) {
                    ++$trusted;
                } else {
                    continue 2;
                }
            }
            // Bounded as the words alike are: each is scored on its own, and
            // a word whose letters are half marks is spelled by every word
            // of the index sharing the other half.
            if ($same >= $trusted) {
                $spelled[] = $indexed;
                if (count($spelled) >= self::MAX_ALIKE) {
                    break;
                }
            }
        }

        return $spelled;
    }

    /**
     * Whether a letter of ASCII may stand for a Latin letter outside it.
     */
    private static function mark(string $ascii, string $marked): bool
    {
        return strlen($ascii) === 1 && $ascii >= 'a' && $ascii <= 'z' && strlen($marked) > 1 && preg_match('/^\p{Latin}$/u', $marked) === 1;
    }

    /**
     * The indexed words alike a word, by the Dice coefficient of their
     * trigrams, at least NEAREST, the MAX_ALIKE most alike, in index order.
     *
     * Only the words sharing a trigram with it are visited, through the
     * inverted trigrams kept with the index: a word sharing none is not
     * alike at all.
     *
     * @return array<array-key, float> word => Dice coefficient; PHP keys a word such as "123" as an integer
     */
    private function alike(string $word): array
    {
        $dices = $this->dices($word);
        ksort($dices);
        if (count($dices) > self::MAX_ALIKE) {
            // arsort is stable since PHP 8.0: among equals the first indexed
            // stays, as in nearest().
            arsort($dices);
            $dices = array_slice($dices, 0, self::MAX_ALIKE, true);
            ksort($dices);
        }
        $words = $this->wordList ?? [];
        $alike = [];
        foreach ($dices as $position => $dice) {
            $alike[$words[$position]] = $dice;
        }

        return $alike;
    }

    /**
     * The position of each indexed word sharing a trigram with a word, with
     * its Dice coefficient, at least NEAREST, in no set order.
     *
     * @return array<int, float>
     */
    private function dices(string $word): array
    {
        $grams = array_unique(Analyzer::trigrams($word));
        if ($grams === []) {
            return [];
        }
        [$wordsByGram, $gramCounts] = $this->nearby ??= self::invert($this->words);
        $shared = [];
        foreach ($grams as $gram) {
            foreach ($wordsByGram[$gram] ?? [] as $position) {
                $shared[$position] = ($shared[$position] ?? 0) + 1;
            }
        }
        $this->wordList ??= array_map('strval', array_keys($this->words));
        $dices = [];
        foreach ($shared as $position => $count) {
            $dice = 2 * $count / (count($grams) + ($gramCounts[$position] ?? 0));
            if ($dice >= self::NEAREST && isset($this->wordList[$position])) {
                $dices[$position] = $dice;
            }
        }

        return $dices;
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
            'nearby' => $this->nearby,
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
        $nearby = $saved['nearby'] ?? null;
        if ($nearby !== null && !(is_array($nearby) && is_array($nearby[0] ?? null) && is_array($nearby[1] ?? null))) {
            return null;
        }
        /** @var array{array<array-key, list<int>>, list<int>}|null $nearby */
        return new self($analyzer, $ids, $words, $grams, (float) $saved['trigramWeight'], $nearby);
    }
}
