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
 * Cuts text into the terms the index compares.
 *
 * Two kinds of term: whole words, which carry precision, and trigrams of
 * each word, which let "statuses" meet "status" or "categories" meet
 * "category" with no rule of any language. Words are not reduced to a stem:
 * measured on real tool lists, stemming made more tools tie, because a plural
 * in a query ("categories") is itself the sign the caller wants a list.
 */
final class Analyzer
{
    /**
     * A letter of a script written without spaces between words, as Unicode
     * names them. Letters only: PCRE matches the scripts by their extensions,
     * which also hold the ideographic comma and full stop.
     */
    private const UNSPACED = '(?:(?=\p{L})[\p{Han}\p{Hiragana}\p{Katakana}])';

    /**
     * Thai, also written without spaces between words, but whose vowels and
     * tone marks are marks: its runs are cut into pairs of characters as
     * they are seen (grapheme clusters), not into pairs of code points.
     */
    private const THAI = '\p{Thai}';

    /**
     * A Hangul letter. Korean puts spaces between words, but glues to each
     * word the particles that mark its role ("레슨을", the lesson as object):
     * the word a tool writes ("레슨") is then never a token of the request.
     */
    private const HANGUL = '\p{Hangul}';

    /** @var array<string, true> */
    private readonly array $stopWords;

    /**
     * @param list<string> $stopWords words the host wants ignored. None by
     *        default: BM25 already gives a word found in every tool almost no
     *        weight, so the library needs no list of its own, in any language.
     */
    public function __construct(array $stopWords = [])
    {
        $this->stopWords = array_fill_keys(array_map('strtolower', $stopWords), true);
    }

    /**
     * The terms the index compares: the tokens of the text, with each run of
     * Han or kana and each Korean word cut into overlapping pairs of
     * characters, and each run of Thai into pairs of grapheme clusters (see
     * thai()).
     *
     * Chinese and Japanese are written without spaces, so a whole sentence is
     * one token and would only ever meet a tool by its trigrams, while most of
     * their words are two characters long and give no trigram of their own.
     * Pairs of characters are what CJK search has indexed for decades (the
     * bigrams of Lucene's CJK analyzer): they need no dictionary, and the
     * scripts come from Unicode, not from a list in the library. That analyzer
     * pairs Hangul too, and for the same reason it is done here: a Korean word
     * carries its particles, and its pairs meet the bare word of the tool
     * where the word itself would not. Korean keeps its spaces, so each word
     * is paired on its own.
     *
     * @return list<string>
     */
    public function words(string $text): array
    {
        $terms = [];
        foreach ($this->tokens($text) as $token) {
            foreach (self::terms($token) as $term) {
                if (!isset($this->stopWords[$term])) {
                    $terms[] = $term;
                }
            }
        }

        return $terms;
    }

    /**
     * The words of a text as it writes them, lowercased, numbers and the
     * host's stop words left out: what the caller is shown back, where a pair
     * cut out of a sentence would mean nothing.
     *
     * snake_case, kebab-case, dotted names and camelCase are split, so a tool
     * named set_ticket_status or setTicketStatus matches "ticket status". A
     * run of Han, kana or Thai is a token of its own, apart from the letters
     * and digits around it ("5栋101").
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $split = preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1 $2', $text);
        $split = is_string($split) ? $split : $text;
        // Letters and digits of any script: the library holds no alphabet of
        // its own. mbstring lowers every script when the server has it;
        // without it, strtolower() lowers ASCII, which covers the English
        // most tool lists are written in.
        $lower = function_exists('mb_strtolower') ? mb_strtolower($split, 'UTF-8') : strtolower($split);
        // Marks belong to the word they sit in. Devanagari, Thai, Tamil and
        // the other Brahmic scripts write most vowels as marks, Arabic and
        // Hebrew their vowel points, and decomposed text (NFD) every accent:
        // splitting on them cut "सर्वर" into "सर" and "वर", one word into
        // fragments that meet unrelated tools.
        $parts = preg_split('/[^\p{L}\p{M}\p{N}]+|(' . self::UNSPACED . '+|' . self::THAI . '+)/u', $lower, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $words = [];
        foreach ($parts === false ? [] : $parts as $part) {
            // A number is an identifier ("ticket 12"), never a word that tells
            // tools apart, and its trigrams would only add noise.
            if (preg_match('/^\p{N}+$/u', $part) !== 1 && !isset($this->stopWords[$part])) {
                $words[] = $part;
            }
        }

        return $words;
    }

    /**
     * The terms of one token: itself, or its pairs of characters when it is a
     * run of Han or kana or a Korean word longer than one character, or a run
     * of Thai.
     *
     * @return list<string>
     */
    public static function terms(string $token): array
    {
        if (preg_match('/^' . self::THAI . '+$/u', $token) === 1) {
            return self::thai($token);
        }
        // A Korean word gives its pairs only, as Chinese does: measured on the
        // train half of the music school, keeping the word beside them found
        // fewer tools first and returned more.
        if (preg_match('/^(?:' . self::UNSPACED . '{2,}|' . self::HANGUL . '{2,})$/u', $token) !== 1) {
            return [$token];
        }
        $characters = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return [$token];
        }
        $pairs = [];
        for ($i = 0, $last = count($characters) - 1; $i < $last; ++$i) {
            $pairs[] = $characters[$i] . $characters[$i + 1];
        }

        return $pairs;
    }

    /**
     * The terms of a run of Thai: its overlapping pairs of grapheme clusters,
     * and the run itself.
     *
     * A pair of code points would pair a consonant with the vowel or tone
     * mark it carries, a fragment that means nothing; a cluster is a
     * consonant with its marks, as \X has always matched in PCRE. The run is
     * kept beside its pairs, unlike Chinese: its trigrams were what found
     * Thai tools before, and measured on the train half of the sports centre,
     * keeping it found as many tools first with fewer tools returned.
     *
     * @return list<string>
     */
    private static function thai(string $token): array
    {
        if (preg_match_all('/\X/u', $token, $clusters) === false || count($clusters[0]) < 3) {
            return [$token];
        }
        $terms = [];
        for ($i = 0, $last = count($clusters[0]) - 1; $i < $last; ++$i) {
            $terms[] = $clusters[0][$i] . $clusters[0][$i + 1];
        }
        $terms[] = $token;

        return $terms;
    }

    /**
     * The trigrams of a word, padded so its start and end count too.
     *
     * @return list<string>
     */
    public static function trigrams(string $word): array
    {
        $padded = ' ' . $word . ' ';
        // Most tool lists are ASCII, where a byte is a character: cutting
        // bytes is several times faster than splitting into characters.
        if (preg_match('/[\x80-\xFF]/', $padded) !== 1) {
            $grams = [];
            for ($i = 0, $last = strlen($padded) - 3; $i <= $last; ++$i) {
                $grams[] = substr($padded, $i, 3);
            }

            return $grams;
        }
        // Characters, not bytes, so a word outside ASCII gives whole trigrams.
        $characters = preg_split('//u', $padded, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return [];
        }
        $grams = [];
        for ($i = 0, $last = count($characters) - 3; $i <= $last; ++$i) {
            $grams[] = $characters[$i] . $characters[$i + 1] . $characters[$i + 2];
        }

        return $grams;
    }
}
