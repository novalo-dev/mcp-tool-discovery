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
     * The words of a text, lowercased, numbers and the host's stop words left out.
     *
     * snake_case, kebab-case, dotted names and camelCase are split, so a tool
     * named set_ticket_status or setTicketStatus matches "ticket status".
     *
     * @return list<string>
     */
    public function words(string $text): array
    {
        $split = preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1 $2', $text);
        $split = is_string($split) ? $split : $text;
        // Letters and digits of any script: the library holds no alphabet of
        // its own. mbstring lowers every script when the server has it;
        // without it, strtolower() lowers ASCII, which covers the English
        // most tool lists are written in.
        $lower = function_exists('mb_strtolower') ? mb_strtolower($split, 'UTF-8') : strtolower($split);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower);
        $words = [];
        foreach ($parts === false ? [] : $parts as $part) {
            // A number is an identifier ("ticket 12"), never a word that tells
            // tools apart, and its trigrams would only add noise.
            if ($part !== '' && preg_match('/^\p{N}+$/u', $part) !== 1 && !isset($this->stopWords[$part])) {
                $words[] = $part;
            }
        }

        return $words;
    }

    /**
     * The trigrams of a word, padded so its start and end count too.
     *
     * @return list<string>
     */
    public static function trigrams(string $word): array
    {
        // Characters, not bytes, so a word outside ASCII gives whole trigrams.
        $characters = preg_split('//u', ' ' . $word . ' ', -1, PREG_SPLIT_NO_EMPTY);
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
