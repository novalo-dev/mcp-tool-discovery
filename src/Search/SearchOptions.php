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
 * The settings of a tool search.
 *
 * The defaults were chosen on 150 queries over three servers named in three
 * styles, then checked on 150 others kept aside and on six more servers,
 * in French, in German, in Greek, in Chinese, one with 176 tools and one
 * with long descriptions and no titles, written afterwards:
 * see docs/SEARCH.md. Change them
 * with a benchmark of your own, not by feel.
 */
final class SearchOptions
{
    /** How much a match counts in each field of a tool, unless the host says otherwise. */
    public const WEIGHTS = ['name' => 1.0, 'title' => 2.0, 'description' => 1.0, 'parameters' => 0.5];

    /**
     * How much a match counts in each field of a tool for the description of
     * a request, unless the host says otherwise. The action and the object
     * name a tool; the description says what it should be about, so it is
     * read mostly against what the tools say they do. With the name and the
     * title weighed as for the other fields, a word of it in the titles of
     * other objects won: "details" found "File details" before the user the
     * object named. Chosen on the train half; see docs/SEARCH.md.
     */
    public const DESCRIPTION_WEIGHTS = ['name' => 0.5, 'title' => 0.5, 'description' => 1.0, 'parameters' => 0.5];

    /** How much each field of a query counts, unless the host says otherwise. */
    public const QUERY_WEIGHTS = ['action' => 0.8, 'object' => 1.0, 'description' => 1.0, 'text' => 1.0];

    /** @var array<string, float> every field of a tool, the defaults under what the host gave */
    public readonly array $weights;

    /** @var array<string, float> every field of a tool for the description of a request, the defaults under what the host gave */
    public readonly array $descriptionWeights;

    /** @var array<string, float> every field of a query, the defaults under what the host gave */
    public readonly array $queryWeights;

    /** @var array<string, string> each word lowered as the index lowers the request */
    public readonly array $synonyms;

    /**
     * @param array<string, float> $weights how much a match counts in each field of a tool:
     *        name, title, description, parameters; a field left out keeps its default
     *        and 0 leaves it out of the ranking
     * @param float $trigramWeight how much trigram matches count against whole-word matches
     * @param array<string, float> $queryWeights how much each field of a query counts:
     *        action, object, description, text; a field left out keeps its default
     * @param float $cut tools below this share of the best score are left out
     * @param int $limit the most tools a search returns
     * @param array<string, string> $synonyms a word of the caller => words of the tools,
     *        e.g. ['meeting' => 'calendar event']; the host's own vocabulary, never built in
     * @param list<string> $stopWords words to ignore, none by default
     * @param float $learningWeight how far what was learned may lift a tool, as a share of
     *        the best score; 0 turns learning off even with a store
     * @param int $learningCap the successes after which a word lifts a tool no further
     * @param array<string, float> $descriptionWeights how much a match counts in each field of a tool
     *        for the description of a request, as $weights for the other fields; give it the same
     *        as $weights to read every field of a request alike
     * @param float $textTrigramPower the power of its number of words that the trigram scores of
     *        the text of a request, one sentence, are divided by; 1 divides them as in the other
     *        fields. A sentence has to name the tool alone, often in forms the tools do not write,
     *        and divided by its whole length a word reached the tool that writes it otherwise less
     *        the longer the sentence. Chosen on the train half; see docs/SEARCH.md
     */
    public function __construct(
        array $weights = self::WEIGHTS,
        public readonly float $trigramWeight = 0.6,
        array $queryWeights = self::QUERY_WEIGHTS,
        public readonly float $cut = 0.6,
        public readonly int $limit = 10,
        array $synonyms = [],
        public readonly array $stopWords = [],
        public readonly float $learningWeight = 0.75,
        public readonly int $learningCap = 5,
        array $descriptionWeights = self::DESCRIPTION_WEIGHTS,
        public readonly float $fieldBalance = 0.5,
        public readonly float $textTrigramPower = 0.4,
    ) {
        // NAN passes every comparison below, and would rank every tool NAN.
        if (!is_finite($cut) || $cut < 0 || $cut > 1) {
            throw new \InvalidArgumentException('The cut is a share of the best score, between 0 and 1.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be at least 1.');
        }
        if (!is_finite($learningWeight) || $learningWeight < 0 || $learningCap < 1) {
            throw new \InvalidArgumentException('The learning weight cannot be negative and the cap must be at least 1.');
        }
        if (!is_finite($trigramWeight) || $trigramWeight < 0) {
            throw new \InvalidArgumentException('The trigram weight cannot be negative.');
        }
        if (!is_finite($fieldBalance) || $fieldBalance < 0 || $fieldBalance > 1) {
            throw new \InvalidArgumentException('The field balance is a power of the best score of a field, between 0 and 1.');
        }
        if (!is_finite($textTrigramPower) || $textTrigramPower < 0 || $textTrigramPower > 1) {
            throw new \InvalidArgumentException('The text trigram power is a power of the number of words of a sentence, between 0 and 1.');
        }
        // Merged over the defaults: an array given by name replaces the whole
        // default, so ['title' => 3.0] used to leave the name, the
        // description and the parameters out of the index without a word.
        $this->weights = self::weighed($weights, self::WEIGHTS, 'weights');
        $this->queryWeights = self::weighed($queryWeights, self::QUERY_WEIGHTS, 'queryWeights');
        $this->descriptionWeights = self::weighed($descriptionWeights, self::DESCRIPTION_WEIGHTS, 'descriptionWeights');
        self::strings($stopWords);
        $this->synonyms = self::words($synonyms, new Analyzer($stopWords));
    }

    /**
     * What decides the index, so a cache keeps one index per setting.
     */
    public function indexKey(): string
    {
        return serialize([$this->weights, $this->descriptionWeights, $this->trigramWeight, $this->stopWords]);
    }

    /**
     * The host's weights over the defaults, refused when one could never be
     * read: a field the search does not have ("names", "query") was never
     * looked at, and a ranking that would not move was all the host saw.
     *
     * @param array<string, float> $defaults
     *
     * @return array<string, float>
     */
    private static function weighed(mixed $given, array $defaults, string $option): array
    {
        if (!is_array($given)) {
            throw new \InvalidArgumentException(sprintf('The %s are a map of field => weight.', $option));
        }
        foreach (array_diff_key($given, $defaults) as $field => $weight) {
            throw new \InvalidArgumentException(sprintf('The %s have no field "%s"; the fields are %s.', $option, $field, implode(', ', array_keys($defaults))));
        }
        $weights = $defaults;
        foreach ($defaults as $field => $default) {
            if (!array_key_exists($field, $given)) {
                continue;
            }
            $weight = $given[$field];
            if ((!is_float($weight) && !is_int($weight)) || !is_finite((float) $weight) || $weight < 0) {
                throw new \InvalidArgumentException(sprintf('The weight of "%s" in the %s must be a number of 0 or more.', $field, $option));
            }
            $weights[$field] = (float) $weight;
        }
        if (array_filter($weights, static fn(float $weight): bool => $weight > 0) === []) {
            throw new \InvalidArgumentException(sprintf('At least one of the %s must be above 0, or no tool is ever found.', $option));
        }

        return $weights;
    }

    /**
     * The stop words, refused when one is not a string: its shape is in a
     * docblock only, and an array among them stopped the first search.
     */
    private static function strings(mixed $words): void
    {
        foreach (is_array($words) ? $words : [] as $word) {
            if (!is_string($word)) {
                throw new \InvalidArgumentException('A stop word must be a string.');
            }
        }
    }

    /**
     * The synonyms keyed by the one token each stands for. A key the request
     * can never hold as a token was never met: two words ("team meeting",
     * "e-mail"), a number, a stop word, or a capital strtolower() leaves as
     * it is ("Événement" was never "événement").
     *
     * @return array<string, string>
     */
    private static function words(mixed $synonyms, Analyzer $analyzer): array
    {
        if (!is_array($synonyms)) {
            throw new \InvalidArgumentException('The synonyms are a map of word => words of the tools.');
        }
        $words = [];
        foreach ($synonyms as $word => $meaning) {
            $tokens = $analyzer->tokens((string) $word);
            if (count($tokens) !== 1) {
                throw new \InvalidArgumentException(sprintf('The synonym "%s" is not one word of a request: a key is a single word, not a number or a stop word.', $word));
            }
            if (!is_string($meaning) || trim($meaning) === '') {
                throw new \InvalidArgumentException(sprintf('The synonym "%s" must give the words of the tools as a string.', $word));
            }
            if (isset($words[$tokens[0]])) {
                throw new \InvalidArgumentException(sprintf('The synonyms give "%s" twice.', $tokens[0]));
            }
            $words[$tokens[0]] = $meaning;
        }

        return $words;
    }
}
