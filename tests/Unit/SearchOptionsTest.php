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

namespace Novalo\McpToolDiscovery\Tests\Unit;

use Novalo\McpToolDiscovery\Search\Analyzer;
use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\ToolSearch;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Settings a host gets wrong are refused when they are built, not found out
 * later from a ranking that does not move.
 */
final class SearchOptionsTest extends TestCase
{
    public function testAWeightGivenAloneKeepsTheOtherFields(): void
    {
        $options = new SearchOptions(weights: ['title' => 3.0], queryWeights: ['action' => 0.6]);

        self::assertSame(['name' => 1.0, 'title' => 3.0, 'description' => 1.0, 'parameters' => 0.5], $options->weights);
        self::assertSame(['action' => 0.6, 'object' => 1.0, 'description' => 1.0, 'text' => 1.0], $options->queryWeights);
        // "trash" is only in the description of the delete tool.
        self::assertSame('acme_delete_file', (new ToolSearch(RecordingGate::fixtureTools(), $options))->search('trash')[0]->name ?? null);
    }

    public function testTheDescriptionOfARequestHasWeightsOfItsOwn(): void
    {
        $options = new SearchOptions(descriptionWeights: ['title' => 1.0]);

        self::assertSame(['name' => 0.5, 'title' => 1.0, 'description' => 1.0, 'parameters' => 0.5], $options->descriptionWeights);
        // The weights decide the index, so each setting keeps its own.
        self::assertNotSame((new SearchOptions())->indexKey(), $options->indexKey());
    }

    public function testAWeightOfZeroLeavesAFieldOutOfTheRanking(): void
    {
        $options = new SearchOptions(weights: ['description' => 0]);

        self::assertSame(0.0, $options->weights['description']);
        self::assertNotSame('acme_delete_file', (new ToolSearch(RecordingGate::fixtureTools(), $options))->search('trash')[0]->name ?? null);
    }

    /**
     * @return iterable<string, array{\Closure(): SearchOptions, string}>
     */
    public static function wrongSettings(): iterable
    {
        yield 'a tool field the search does not have' => [static fn(): SearchOptions => new SearchOptions(weights: ['names' => 2.0]), '"names"'];
        yield 'a query field the search does not have' => [static fn(): SearchOptions => new SearchOptions(queryWeights: ['query' => 2.0]), '"query"'];
        yield 'a negative weight' => [static fn(): SearchOptions => new SearchOptions(weights: ['title' => -1.0]), '"title"'];
        yield 'a weight that is not a number' => [static fn(): SearchOptions => new SearchOptions(weights: ['title' => NAN]), '"title"'];
        yield 'every tool field at zero' => [static fn(): SearchOptions => new SearchOptions(weights: ['name' => 0, 'title' => 0, 'description' => 0, 'parameters' => 0]), 'above 0'];
        yield 'a tool field the description does not read' => [static fn(): SearchOptions => new SearchOptions(descriptionWeights: ['summary' => 1.0]), '"summary"'];
        yield 'every tool field at zero for the description' => [static fn(): SearchOptions => new SearchOptions(descriptionWeights: ['name' => 0, 'title' => 0, 'description' => 0, 'parameters' => 0]), 'above 0'];
        yield 'every query field at zero' => [static fn(): SearchOptions => new SearchOptions(queryWeights: ['action' => 0, 'object' => 0, 'description' => 0, 'text' => 0]), 'above 0'];
        yield 'a cut that is not a number' => [static fn(): SearchOptions => new SearchOptions(cut: NAN), 'cut'];
        yield 'a field balance above 1' => [static fn(): SearchOptions => new SearchOptions(fieldBalance: 1.5), 'field balance'];
        yield 'a field balance that is not a number' => [static fn(): SearchOptions => new SearchOptions(fieldBalance: NAN), 'field balance'];
        yield 'a text trigram power above 1' => [static fn(): SearchOptions => new SearchOptions(textTrigramPower: 2.0), 'text trigram power'];
        yield 'a text trigram power that is not a number' => [static fn(): SearchOptions => new SearchOptions(textTrigramPower: NAN), 'text trigram power'];
        yield 'a negative trigram weight' => [static fn(): SearchOptions => new SearchOptions(trigramWeight: -0.1), 'trigram'];
        yield 'an infinite learning weight' => [static fn(): SearchOptions => new SearchOptions(learningWeight: INF), 'learning'];
        yield 'a synonym of two words' => [static fn(): SearchOptions => new SearchOptions(synonyms: ['team meeting' => 'calendar event']), '"team meeting"'];
        yield 'a synonym of a word cut in two' => [static fn(): SearchOptions => new SearchOptions(synonyms: ['e-mail' => 'message']), '"e-mail"'];
        yield 'a synonym that is a stop word' => [static fn(): SearchOptions => new SearchOptions(synonyms: ['please' => 'help'], stopWords: ['please']), '"please"'];
        yield 'a synonym with no words of the tools' => [static fn(): SearchOptions => new SearchOptions(synonyms: ['meeting' => ' ']), '"meeting"'];
        yield 'a synonym given twice' => [static fn(): SearchOptions => new SearchOptions(synonyms: ['Meeting' => 'event', 'meeting' => 'calendar']), 'twice'];
    }

    /**
     * @param \Closure(): SearchOptions $build
     */
    #[DataProvider('wrongSettings')]
    public function testAWrongSettingIsRefusedWhenBuilt(\Closure $build, string $named): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($named);

        $build();
    }

    public function testASynonymWithACapitalOutsideAsciiMeetsTheRequest(): void
    {
        $options = new SearchOptions(synonyms: ['Événement' => 'calendar event']);

        self::assertSame(['événement' => 'calendar event'], $options->synonyms);
        self::assertSame('acme_create_event', (new ToolSearch(RecordingGate::fixtureTools(), $options))->search(new SearchQuery('create', 'ÉVÉNEMENT'))[0]->name ?? null);
    }

    public function testAStopWordWithACapitalOutsideAsciiIsLeftOut(): void
    {
        self::assertSame(['datei'], (new Analyzer(['Über']))->tokens('über Datei'));
    }
}
