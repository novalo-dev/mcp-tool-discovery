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
     * English words that say nothing about which tool is wanted. Tool names
     * and descriptions of MCP servers are written in English, and assistants
     * search them in English.
     */
    private const STOP_WORDS = [
        'a', 'about', 'all', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'does', 'each',
        'for', 'from', 'how', 'i', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'of', 'on', 'one', 'or', 'our',
        'please', 'that', 'the', 'their', 'this', 'to', 'us', 'was', 'we', 'were', 'what', 'which', 'who',
        'with', 'you', 'your',
    ];

    /**
     * Latin letters with accents, brought to plain ASCII without the intl,
     * iconv or mbstring extensions, which shared hosts do not always have.
     */
    private const FOLD = [
        'à' => 'a', 'À' => 'a', 'á' => 'a', 'Á' => 'a', 'â' => 'a', 'Â' => 'a', 'ã' => 'a', 'Ã' => 'a',
        'ä' => 'a', 'Ä' => 'a', 'å' => 'a', 'Å' => 'a', 'ç' => 'c', 'Ç' => 'c', 'è' => 'e', 'È' => 'e',
        'é' => 'e', 'É' => 'e', 'ê' => 'e', 'Ê' => 'e', 'ë' => 'e', 'Ë' => 'e', 'ì' => 'i', 'Ì' => 'i',
        'í' => 'i', 'Í' => 'i', 'î' => 'i', 'Î' => 'i', 'ï' => 'i', 'Ï' => 'i', 'ñ' => 'n', 'Ñ' => 'n',
        'ò' => 'o', 'Ò' => 'o', 'ó' => 'o', 'Ó' => 'o', 'ô' => 'o', 'Ô' => 'o', 'õ' => 'o', 'Õ' => 'o',
        'ö' => 'o', 'Ö' => 'o', 'ù' => 'u', 'Ù' => 'u', 'ú' => 'u', 'Ú' => 'u', 'û' => 'u', 'Û' => 'u',
        'ü' => 'u', 'Ü' => 'u', 'ý' => 'y', 'Ý' => 'y', 'ÿ' => 'y', 'Ÿ' => 'y', 'œ' => 'oe', 'Œ' => 'oe',
        'æ' => 'ae', 'Æ' => 'ae', 'ß' => 'ss',
    ];

    /** @var array<string, true> */
    private readonly array $stopWords;

    /**
     * @param list<string>|null $stopWords null for the built-in English list
     */
    public function __construct(?array $stopWords = null)
    {
        $this->stopWords = array_fill_keys(array_map('strtolower', $stopWords ?? self::STOP_WORDS), true);
    }

    /**
     * The words of a text, lowercased, stop words and numbers left out.
     *
     * snake_case, kebab-case, dotted names and camelCase are split, so a tool
     * named set_ticket_status or setTicketStatus matches "ticket status".
     *
     * @return list<string>
     */
    public function words(string $text): array
    {
        $split = preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1 $2', $text);
        $parts = preg_split('/[^a-z0-9]+/', strtolower(self::fold(is_string($split) ? $split : $text)));
        $words = [];
        foreach ($parts === false ? [] : $parts as $part) {
            // A number is an identifier ("ticket 12"), never a word that tells
            // tools apart, and its trigrams would only add noise.
            if ($part !== '' && preg_match('/^[0-9]+$/', $part) !== 1 && !isset($this->stopWords[$part])) {
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
        $padded = ' ' . $word . ' ';
        $grams = [];
        for ($i = 0, $last = strlen($padded) - 3; $i <= $last; ++$i) {
            $grams[] = substr($padded, $i, 3);
        }

        return $grams;
    }

    private static function fold(string $text): string
    {
        return strtr($text, self::FOLD);
    }
}
