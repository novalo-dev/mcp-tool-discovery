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
     * Han or kana cut into overlapping pairs of characters.
     *
     * Chinese and Japanese are written without spaces, so a whole sentence is
     * one token and would only ever meet a tool by its trigrams, while most of
     * their words are two characters long and give no trigram of their own.
     * Pairs of characters are what CJK search has indexed for decades (the
     * bigrams of Lucene's CJK analyzer): they need no dictionary, and the
     * scripts come from Unicode, not from a list in the library.
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
     * run of Han or kana is a token of its own, apart from the letters and
     * digits around it ("5栋101").
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
        $parts = preg_split('/[^\p{L}\p{N}]+|(' . self::UNSPACED . '+)/u', $lower, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
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
     * run of Han or kana longer than one character.
     *
     * @return list<string>
     */
    public static function terms(string $token): array
    {
        if (preg_match('/^' . self::UNSPACED . '{2,}$/u', $token) !== 1) {
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
