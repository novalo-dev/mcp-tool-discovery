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

namespace Novalo\McpToolDiscovery\Tests\Search;

use Novalo\McpToolDiscovery\Search\Analyzer;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\Counts;
use Novalo\McpToolDiscovery\Search\Learning\FileLearningStore;
use Novalo\McpToolDiscovery\Search\Learning\LearningStore;
use Novalo\McpToolDiscovery\Search\Learning\MemoryLearningStore;
use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\ToolIndex;
use Novalo\McpToolDiscovery\Search\ToolSearch;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\TestCase;

final class ToolSearchTest extends TestCase
{
    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, $tools);
    }

    private static function temporary(string $name): string
    {
        return sys_get_temp_dir() . '/mcp-tool-discovery-' . $name . '-' . bin2hex(random_bytes(6));
    }

    public function testTheAnalyzerSplitsEveryNamingStyle(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['set', 'ticket', 'status'], $analyzer->words('set_ticket_status'));
        self::assertSame(['set', 'ticket', 'status'], $analyzer->words('setTicketStatus'));
        self::assertSame(['people', 'leave', 'approve'], $analyzer->words('people.leave.approve'));
        self::assertSame(['get', 'html', 'page'], $analyzer->words('getHTMLPage'));
    }

    public function testTheAnalyzerHoldsNoListOfItsOwn(): void
    {
        self::assertSame(['close', 'the', 'ticket'], (new Analyzer())->words('Close the ticket 1042'), 'numbers left out, every word kept');
        self::assertSame(['close', 'ticket'], (new Analyzer(['the']))->words('Close the ticket'), 'stop words only when the host gives them');
        self::assertContains('statut', (new Analyzer())->words('Changer le statut'), 'any language');
    }

    public function testTrigramsAreCutByCharacterInEveryScript(): void
    {
        self::assertSame([' st', 'sta', 'tat', 'atu', 'tus', 'us '], Analyzer::trigrams('status'), 'ASCII, cut by bytes');
        self::assertSame([' pr', 'prê', 'rêt', 'êt '], Analyzer::trigrams('prêt'), 'a letter outside ASCII stays whole');
        self::assertSame([' ab', 'ab '], Analyzer::trigrams('ab'));
        self::assertSame([], Analyzer::trigrams(''));
    }

    public function testTextWrittenWithoutSpacesIsCutInPairs(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['帮我', '我报', '报修'], $analyzer->words('帮我报修'));
        self::assertSame(['帮我报修'], $analyzer->tokens('帮我报修'), 'the caller is shown the word it wrote');
        self::assertSame(['派工', '维修'], $analyzer->words('派工、维修。'), 'no pair holds a punctuation mark');
        self::assertSame(['栋', '有快', '快递', '递吗'], $analyzer->words('5栋101有快递吗'), 'numbers apart, still left out');
        self::assertSame(['报'], $analyzer->words('报'));
        self::assertSame(['カー', 'ード'], $analyzer->words('カード'), 'kana too');
        self::assertSame(['登记', '记一'], (new Analyzer(['一下']))->words('登记一下'), 'a host stop word can be a pair');
        self::assertSame(['ticket', 'status'], $analyzer->words('ticket status'), 'spaced scripts unchanged');
    }

    public function testThaiIsCutInPairsOfWhatIsSeen(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['ยก', 'กเ', 'เลิ', 'ลิก', 'ยกเลิก'], $analyzer->words('ยกเลิก'), 'a vowel or tone mark stays on its consonant, the run is kept');
        self::assertSame(['ยกเลิก'], $analyzer->tokens('ยกเลิก'), 'the caller is shown the word it wrote');
        self::assertSame(['ตู้เบอร์'], $analyzer->tokens('ตู้เบอร์12'), 'numbers apart, still left out');
        self::assertSame(['ตู้'], $analyzer->words('ตู้'), 'one cluster, one term');
    }

    public function testASentenceInThaiFindsItsTool(): void
    {
        $search = new ToolSearch(RecordingGate::server('arena'));

        // Both found another tool first while a sentence met tools by its trigrams only.
        self::assertSame('lockers_list', $search->search('ตอนนี้ตู้ล็อกเกอร์ว่างกี่ตู้')[0]->name ?? null);
        self::assertSame('staff_shifts', $search->search('พรุ่งนี้ใครเข้ากะหน้าเคาน์เตอร์')[0]->name ?? null);
    }

    public function testBurmeseIsCutInPairsOfWhatIsSeen(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['ပယ်', 'ယ်ဖျ', 'ဖျက်', 'ပယ်ဖျက်'], $analyzer->words('ပယ်ဖျက်'), 'a stacked consonant or a vowel stays on its consonant, the run is kept');
        self::assertSame(['ပယ်ဖျက်'], $analyzer->tokens('ပယ်ဖျက်'), 'the caller is shown the word it wrote');
    }

    public function testASentenceInBurmeseFindsItsTool(): void
    {
        $search = new ToolSearch(RecordingGate::server('mill'));

        // Both found another tool first while a run of Burmese met tools by its trigrams only.
        self::assertSame('unpaid_receipts', $search->search('ဘယ်သူ့ကို စပါးဖိုးမပေးရသေးလဲ')[0]->name ?? null);
        self::assertSame('truck_book', $search->search('ဗုဒ္ဓဟူးနေ့ ကွင်းကြီးရွာကစပါးသယ်ဖို့ ကားငှားပါ')[0]->name ?? null);
    }

    public function testLaoIsCutInPairsOfWhatIsSeen(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['ຍົກ', 'ກເ', 'ເລີ', 'ລີກ', 'ຍົກເລີກ'], $analyzer->words('ຍົກເລີກ'), 'a vowel or a tone mark stays on its consonant, the run is kept');
        self::assertSame(['ຍົກເລີກ'], $analyzer->tokens('ຍົກເລີກ'), 'the caller is shown the word it wrote');
    }

    public function testASentenceInLaoFindsItsTool(): void
    {
        $search = new ToolSearch(RecordingGate::server('post'));

        // Both found another tool first while a run of Lao met tools by its trigrams only.
        self::assertSame('parcel_track', $search->search('ພັດສະດຸຂອງຂ້ອຍຢູ່ໃສແລ້ວ')[0]->name ?? null);
        self::assertSame('box_renew', $search->search('ຕູ້ຂອງຂ້ອຍໝົດອາຍຸເດືອນໜ້າ')[0]->name ?? null);
    }

    public function testAKoreanWordIsCutInPairs(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['레슨', '슨을'], $analyzer->words('레슨을'), 'the word with its particle meets the bare word');
        self::assertSame(['레슨을', '취소해'], $analyzer->tokens('레슨을 취소해'), 'the caller is shown the words it wrote');
        self::assertSame(['레슨'], $analyzer->words('레슨'), 'two syllables, one pair');
        self::assertSame(['줘'], $analyzer->words('줘'), 'one syllable, one term');
    }

    public function testASentenceInKoreanFindsItsTool(): void
    {
        $search = new ToolSearch(RecordingGate::server('academy'));

        // Both found another tool first while a word was compared with its particles.
        self::assertSame('recital_cancel', $search->search('이번 발표회는 취소해 줘')[0]->name ?? null);
        self::assertSame('instrument_lend', $search->search('하준이에게 첼로를 빌려 줘')[0]->name ?? null);
    }

    public function testAMarkStaysInItsWord(): void
    {
        $analyzer = new Analyzer();

        self::assertSame(['सर्वर', 'बंद', 'करें'], $analyzer->tokens('सर्वर बंद करें'), 'Devanagari vowel signs and virama');
        self::assertSame(['ยกเลิก'], $analyzer->tokens('ยกเลิก'), 'Thai vowels and tone marks');
        self::assertSame(["re\u{301}sume\u{301}"], $analyzer->tokens("re\u{301}sume\u{301}"), 'decomposed accents');
        self::assertSame(['सर्वर'], $analyzer->words('सर्वर'), 'one term, no fragment');
    }

    public function testEachLetterIsLoweredOnItsOwnOnEveryVersionOfPhp(): void
    {
        $analyzer = new Analyzer();

        // The full mapping gave "i" and a combining dot, a word "izin" never met.
        self::assertSame(['izin', 'kaydet'], $analyzer->tokens('İzin kaydet'), 'the Turkish capital dotted i');
        // From PHP 8.3 the full mapping picked the final sigma by context:
        // the same request found other tools on 8.2 than on 8.3.
        self::assertSame(['ακυρωσησ', 'κρατησησ'], $analyzer->tokens('ΑΚΥΡΩΣΗΣ ΚΡΑΤΗΣΗΣ'), 'a Greek capital sigma');
        self::assertSame(['κράτησης'], $analyzer->tokens('κράτησης'), 'a word is shown back as it is written');
    }

    public function testAWordWithMarksFindsItsTool(): void
    {
        $search = new ToolSearch(RecordingGate::server('depot'));

        // Both found the wrong tool first while each vowel cut a word in two.
        self::assertSame('route_get', $search->search('जयपुर-अजमेर रूट के सारे स्टॉप बताओ')[0]->name ?? null);
        self::assertSame('bus_retire', $search->search('15 साल पुरानी बस 042 को बेड़े से निकाल दो')[0]->name ?? null);
    }

    public function testASentenceWithoutSpacesFindsItsTool(): void
    {
        $search = new ToolSearch(RecordingGate::server('estate'));

        self::assertSame('repair_rate', $search->search('我要评价刚才的维修')[0]->name ?? null);
        self::assertSame('notice_publish', $search->search('明晚停电要告诉全楼')[0]->name ?? null);

        $result = $search->find(SearchQuery::text('彩虹独角兽 hello'));
        self::assertSame([], $result->tools);
        self::assertSame(['彩虹独角兽', 'hello'], $result->unmatched, 'whole words, not the pairs cut out of them');

        $vocabulary = $search->vocabulary(50);
        self::assertContains('报修单列表', $vocabulary, 'a title the caller can write again');
        self::assertNotContains('单列', $vocabulary);
    }

    public function testAHostSynonymMeetsAPairInsideASentence(): void
    {
        $tools = RecordingGate::server('estate');
        $plain = self::names((new ToolSearch($tools))->search('派单给张师傅'));
        $host = self::names((new ToolSearch($tools, new SearchOptions(synonyms: ['派单' => '派工'])))->search('派单给张师傅'));

        self::assertNotSame($plain, $host, 'the synonym was added');
        self::assertSame(['repair_assign'], $host);
    }

    public function testTheFieldsOfAQueryFindTheRightTool(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools());

        self::assertSame('acme_set_ticket_status', $search->search(new SearchQuery('change', 'ticket status', 'move a ticket to done'))[0]->name);
        self::assertSame('acme_rollback_deployment', $search->search(new SearchQuery('roll back', 'deployment'))[0]->name);
        self::assertSame('acme_list_backups', $search->search(new SearchQuery('list', 'backups'))[0]->name);
    }

    public function testNoNamingConventionIsAssumed(): void
    {
        $camel = new ToolSearch(RecordingGate::server('platform'));
        $dotted = new ToolSearch(RecordingGate::server('people'));

        self::assertSame('archiveRepository', $camel->search(new SearchQuery('archive', 'repository'))[0]->name);
        self::assertSame('people.hire', $dotted->search(new SearchQuery('add', 'new employee', 'someone joins the company'))[0]->name);
    }

    public function testFarWeakerMatchesAreCut(): void
    {
        $found = (new ToolSearch(RecordingGate::fixtureTools()))->search('webhooks');

        self::assertLessThanOrEqual(5, count($found), 'not every tool that mentions an event');
        self::assertContains('acme_list_webhooks', self::names($found));
    }

    public function testTheLimitHolds(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools(), new SearchOptions(cut: 0.0, limit: 3));

        self::assertCount(3, $search->search('ticket'));
        self::assertCount(1, $search->search('ticket', 1));
    }

    public function testALongRequestIsReadOnlyToItsStart(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools());
        $padded = 'webhooks' . str_repeat(' ', 2000) . 'tickets';

        self::assertSame(self::names($search->search('webhooks')), self::names($search->search($padded)), 'words far past the start are not read');
        $result = $search->find(SearchQuery::text('webhooks ' . str_repeat('é', 1000)));
        self::assertTrue(preg_match('//u', implode(' ', $result->unmatched)) === 1, 'the cut falls between two characters');
    }

    public function testWordsNoToolUsesCostLittleOnALargeIndex(): void
    {
        // A thousand tools whose words all begin alike, so every word of the
        // request is alike thousands of indexed words.
        $definitions = [];
        for ($tool = 0; $tool < 1000; ++$tool) {
            $words = [];
            for ($word = 0; $word < 8; ++$word) {
                $words[] = 'rec' . substr(md5($tool . '-' . $word), 0, 4);
            }
            $definitions[] = ['name' => 'tool_' . $tool, 'description' => implode(' ', $words), 'inputSchema' => ['type' => 'object']];
        }
        $search = new ToolSearch(ToolSet::of($definitions));
        $search->find(SearchQuery::text('recx'));
        $field = '';
        for ($word = 0; $word < 146; ++$word) {
            $field .= sprintf('rec%03d ', $word);
        }

        $start = microtime(true);
        $result = $search->find(new SearchQuery($field, $field, $field, $field));

        self::assertNotSame([], $result->tools);
        self::assertLessThan(1.0, microtime(true) - $start, 'the words alike each unknown word are bounded');
    }

    public function testWordsWithMarksCostLittleOnALargeIndex(): void
    {
        // A thousand tools whose words all share their first half, so a word
        // whose second half is marks is spelled by every word of the index.
        $definitions = [];
        for ($tool = 0; $tool < 1000; ++$tool) {
            $words = [];
            for ($word = 0; $word < 8; ++$word) {
                $hash = md5($tool . '-' . $word);
                $spelled = 'recx';
                for ($letter = 0; $letter < 4; ++$letter) {
                    $spelled .= chr(97 + hexdec(substr($hash, 2 * $letter, 2)) % 26);
                }
                $words[] = $spelled;
            }
            $definitions[] = ['name' => 'tool_' . $tool, 'description' => implode(' ', $words), 'inputSchema' => ['type' => 'object']];
        }
        $search = new ToolSearch(ToolSet::of($definitions));
        $search->find(SearchQuery::text('recxǎ'));
        $field = '';
        foreach (['ǎ', 'ä', 'á', 'à', 'â', 'ã', 'å', 'ā', 'ă', 'ą', 'ȁ', 'ȃ', 'ạ', 'ả', 'ấ', 'ầ', 'ẩ', 'ẫ', 'ậ', 'ắ', 'ằ', 'ẳ', 'ẵ', 'ặ', 'ǻ', 'ǟ', 'ǡ', 'ḁ', 'ẚ', 'ⱥ', 'ɐ', 'ɑ'] as $mark) {
            $field .= 'recx' . str_repeat($mark, 4) . ' ';
        }

        $start = microtime(true);
        $result = $search->find(new SearchQuery($field, $field, $field, $field));

        self::assertNotSame([], $result->tools);
        self::assertLessThan(1.0, microtime(true) - $start, 'the words a word spells but for its marks are bounded');
    }

    public function testWordsWithMarksThatSpellNothingCostLittleOnALargeIndex(): void
    {
        // Three thousand tools whose words all have eight letters, and words
        // with marks that spell none of them: each is held against every
        // word of its length, with no bound to stop at.
        $definitions = [];
        for ($tool = 0; $tool < 3000; ++$tool) {
            $words = [];
            for ($word = 0; $word < 8; ++$word) {
                $hash = md5($tool . '-' . $word);
                $spelled = 'recx';
                for ($letter = 0; $letter < 4; ++$letter) {
                    $spelled .= chr(97 + hexdec(substr($hash, 2 * $letter, 2)) % 26);
                }
                $words[] = $spelled;
            }
            $definitions[] = ['name' => 'tool_' . $tool, 'description' => implode(' ', $words), 'inputSchema' => ['type' => 'object']];
        }
        $search = new ToolSearch(ToolSet::of($definitions));
        $search->find(SearchQuery::text('recxǎ'));
        $field = '';
        for ($word = 0; $word < 32; ++$word) {
            $field .= 'qzx' . chr(97 + $word % 26) . 'ǎǎǎǎ ';
        }

        $start = microtime(true);
        $search->find(new SearchQuery($field, $field, $field, $field));

        self::assertLessThan(0.25, microtime(true) - $start, 'one pass of PCRE keeps the words that could be spelled');
    }

    public function testAHugeRequestCostsLittleMemory(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools());
        $peak = memory_get_peak_usage();
        $search->find(new SearchQuery(str_repeat('a', 2_000_000), str_repeat('ab ', 700_000), str_repeat('é', 1_000_000), str_repeat('x', 2_000_000)));

        // The request itself is about 8 MB; reading it must not cost much more.
        self::assertLessThan($peak + 16_000_000, memory_get_peak_usage());
    }

    public function testNothingMatchesNothing(): void
    {
        $result = (new ToolSearch(RecordingGate::fixtureTools()))->find(SearchQuery::text('zzzz qqqq'));

        self::assertSame([], $result->tools);
        self::assertSame(['zzzz', 'qqqq'], $result->unmatched);
        self::assertSame([], (new ToolSearch(RecordingGate::fixtureTools()))->search(new SearchQuery()));
    }

    public function testTheSameQueryAlwaysGivesTheSameOrder(): void
    {
        $search = new ToolSearch(RecordingGate::fixtureTools(), new SearchOptions(cut: 0.0));

        self::assertSame(self::names($search->search('delete')), self::names($search->search('delete')));
    }

    public function testHostSynonymsBringTheHostVocabulary(): void
    {
        $tools = RecordingGate::fixtureTools();
        $query = new SearchQuery('create', 'meeting');

        self::assertNotSame('acme_create_event', (new ToolSearch($tools))->search($query)[0]->name ?? null);
        self::assertSame('acme_create_event', (new ToolSearch($tools, new SearchOptions(synonyms: ['meeting' => 'calendar event'])))->search($query)[0]->name);
    }

    public function testTheVocabularyComesFromTheToolsThemselves(): void
    {
        $vocabulary = (new ToolSearch(RecordingGate::fixtureTools()))->vocabulary();

        self::assertNotSame([], $vocabulary);
        self::assertContains('ticket', $vocabulary);
        foreach ($vocabulary as $word) {
            self::assertTrue(RecordingGate::fixtureTools()->filter(static fn(Tool $tool): bool => str_contains(strtolower($tool->title()), $word))->names() !== [], $word . ' is in a title');
        }
    }

    public function testACachedIndexAnswersLikeAFreshOne(): void
    {
        $tools = RecordingGate::fixtureTools();
        $query = new SearchQuery('change', 'ticket status');
        $fresh = self::names((new ToolSearch($tools))->search($query));
        $cache = new MemoryIndexCache();
        new ToolSearch($tools, cache: $cache);

        self::assertSame($fresh, self::names((new ToolSearch($tools, cache: $cache))->search($query)));

        $directory = self::temporary('cache');
        new ToolSearch($tools, cache: new FileIndexCache($directory));
        self::assertCount(1, glob($directory . '/*.php') ?: []);
        self::assertSame($fresh, self::names((new ToolSearch($tools, cache: new FileIndexCache($directory)))->search($query)));
        array_map('unlink', glob($directory . '/*.php') ?: []);
        rmdir($directory);
    }

    public function testEachVisibleSetHasItsOwnIndex(): void
    {
        $cache = new MemoryIndexCache();
        $all = RecordingGate::fixtureTools();
        $fewer = $all->filter(static fn(Tool $tool): bool => $tool->name !== 'acme_set_ticket_status');
        new ToolSearch($all, cache: $cache);

        // Built over the tools this caller may see, never read from an index
        // that holds a tool hidden from them.
        self::assertNotContains('acme_set_ticket_status', self::names((new ToolSearch($fewer, cache: $cache))->search(new SearchQuery('change', 'ticket status'))));
    }

    public function testABrokenCacheEntryIsBuiltAgain(): void
    {
        self::assertNull(ToolIndex::fromArray(['format' => 0]));
        self::assertNull(ToolIndex::fromArray([]));
        // The shape saved before the index was inverted: rebuilt, never misread.
        self::assertNull(ToolIndex::fromArray(['format' => 1, 'documents' => [], 'weights' => [], 'averages' => [], 'words' => [], 'grams' => [], 'trigramWeight' => 0.6]));
    }

    public function testARestoredIndexScoresExactlyLikeTheBuiltOne(): void
    {
        $documents = [];
        foreach (RecordingGate::fixtureTools() as $name => $tool) {
            $documents[$name] = ['name' => $name, 'title' => $tool->title(), 'description' => $tool->description()];
        }
        $built = ToolIndex::build($documents, ['name' => 1.0, 'title' => 2.0, 'description' => 1.0]);
        $restored = ToolIndex::fromArray($built->toArray());

        self::assertNotNull($restored);
        self::assertSame($built->ids(), $restored->ids());
        foreach (['change the ticket status', 'roll back a deployment', 'statuses', 'nothing like it'] as $query) {
            self::assertSame($built->score($query), $restored->score($query));
        }
    }

    public function testTheClosestWordIsTheSameHoweverTheIndexWasMade(): void
    {
        $documents = [];
        foreach (RecordingGate::fixtureTools() as $name => $tool) {
            $documents[$name] = ['name' => $name, 'title' => $tool->title(), 'description' => $tool->description()];
        }
        $weights = ['name' => 1.0, 'title' => 2.0, 'description' => 1.0];
        $plain = ToolIndex::build($documents, $weights);
        $learning = ToolIndex::build($documents, $weights, nearby: true);
        $restored = ToolIndex::fromArray($learning->toArray());

        // Built with the index only when asked (a host with a cache asks),
        // otherwise at the first word no tool uses.
        self::assertNull($plain->toArray()['nearby']);
        self::assertNotNull($learning->toArray()['nearby']);
        self::assertNotNull($restored);
        foreach (['tickets', 'statuss', 'deploying', 'rollbak', 'zzzz', '42x', 'ticket'] as $word) {
            $nearest = $plain->nearest($word);
            self::assertSame($nearest, $learning->nearest($word), $word);
            self::assertSame($nearest, $restored->nearest($word), $word);
        }
        self::assertSame('rollback', $plain->nearest('rollbak'));
        self::assertNull($plain->nearest('zzzz'));
    }

    public function testAWordAlikeAsAWholeCountsAsThatWord(): void
    {
        $tools = RecordingGate::fixtureTools();
        $query = new SearchQuery('resolve', 'ticket', 'mark the wifi issue as fixed');

        // "resolve" and "fixed" are in no tool. By the trigrams of the whole
        // request alone, the tool that labels a ticket came first; the
        // status tool holds "solved", alike "resolve" as a whole.
        self::assertSame('acme_set_ticket_status', self::names((new ToolSearch($tools))->search($query))[0]);
    }

    public function testLearningLiftsTheToolThatWorked(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        $query = new SearchQuery('show', 'ticket');
        $before = self::names((new ToolSearch($tools, learning: $store))->search($query));
        $chosen = $before[1];
        for ($run = 0; $run < 5; ++$run) {
            $search = new ToolSearch($tools, learning: $store);
            $search->learn((string) $search->find($query)->searchId, $chosen);
        }

        self::assertSame($chosen, self::names((new ToolSearch($tools, learning: $store))->search($query))[0]);
        self::assertSame($before, self::names((new ToolSearch($tools))->search($query)), 'nothing changes without a store');
    }

    public function testLearningKeepsOnlyTheWordsOfTheTools(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        $search = new ToolSearch($tools, learning: $store);
        $id = $search->find(new SearchQuery('show', 'ticket of Jane Doe'))->searchId;

        $search->learn((string) $id, 'acme_get_ticket');
        $search->learn((string) $id, 'acme_not_a_tool');
        $search->learn(rtrim(strtr(base64_encode('ticket zzsecret'), '+/', '-_'), '='), 'acme_get_ticket');

        $words = array_keys($store->load());
        self::assertContains('ticket', $words);
        self::assertNotContains('jane', $words);
        self::assertNotContains('zzsecret', $words);
        foreach ($store->load() as $byTool) {
            self::assertSame(['acme_get_ticket'], array_keys($byTool));
        }
    }

    public function testLearningNeverBringsAToolTheQueryDoesNotDescribe(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        $store->record(['backup', 'list'], 'acme_delete_file');

        self::assertNotContains('acme_delete_file', self::names((new ToolSearch($tools, learning: $store))->search(new SearchQuery('list', 'backups'))));
    }

    public function testAWordSharedWithALearnedSearchLiftsNothing(): void
    {
        $tools = RecordingGate::fixtureTools();
        $store = new MemoryLearningStore();
        for ($run = 0; $run < 5; ++$run) {
            $search = new ToolSearch($tools, learning: $store);
            $search->learn((string) $search->find(SearchQuery::text('show ticket'))->searchId, 'acme_get_ticket');
        }
        $assign = SearchQuery::text('assign the ticket');

        // "ticket" alone says which object, not which action: what was
        // learned for showing a ticket must not reorder the tools that
        // assign one.
        self::assertSame(self::names((new ToolSearch($tools))->search($assign)), self::names((new ToolSearch($tools, learning: $store))->search($assign)));
        self::assertSame('acme_get_ticket', self::names((new ToolSearch($tools, learning: $store))->search(SearchQuery::text('show ticket')))[0]);
    }

    public function testAPairSharedWithANeighbourBarelyMovesIt(): void
    {
        $tools = RecordingGate::server('sensors');
        $store = new MemoryLearningStore();
        $search = new ToolSearch($tools, learning: $store);
        $search->learn((string) $search->find(new SearchQuery('add', 'site', 'address'))->searchId, 'post_sites');
        $change = new SearchQuery('change', 'site', 'address');

        // Adding a site and changing its address share "site address", one
        // pair of three: the site that was added once must not take the
        // place of the update, while the same request asked again finds it.
        self::assertSame('patch_sites_site_id', self::names((new ToolSearch($tools))->search($change))[0]);
        self::assertSame('patch_sites_site_id', self::names((new ToolSearch($tools, learning: $store))->search($change))[0]);
        self::assertSame('post_sites', self::names((new ToolSearch($tools, learning: $store))->search(new SearchQuery('add', 'site', 'address')))[0]);
    }

    public function testASearchLearnedForANeighbourLiftsItOnlyAsFarAsTheQueryHoldsIt(): void
    {
        $tools = ToolSet::of([
            ['name' => 'instruments_list', 'title' => 'Instruments free to lend', 'description' => 'Lists every instrument one can lend.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'instrument_lend', 'title' => 'Lend an instrument', 'inputSchema' => ['type' => 'object']],
        ]);
        $store = new MemoryLearningStore();
        $view = new SearchQuery('view', 'instrument', 'free to lend');
        $search = new ToolSearch($tools, learning: $store);
        $search->learn((string) $search->find($view)->searchId, 'instruments_list');
        $lend = new SearchQuery('lend', 'instrument', 'cello');

        // Viewing what is free to lend taught the list the pair "instrument
        // lend", the only pair of a request to lend one: the list must not
        // take the place of the loan, as the view, asked again, keeps it.
        self::assertSame('instrument_lend', self::names((new ToolSearch($tools))->search($lend))[0]);
        self::assertSame('instrument_lend', self::names((new ToolSearch($tools, learning: $store))->search($lend))[0]);
        self::assertSame('instruments_list', self::names((new ToolSearch($tools, learning: $store))->search($view))[0]);
    }

    public function testAVerbTheToolsWriteAnotherWayIsLearnedWithItsObject(): void
    {
        $tools = ToolSet::of([
            ['name' => 'approve_estimate', 'title' => 'Approve an estimate', 'inputSchema' => ['type' => 'object']],
            ['name' => 'send_estimate', 'title' => 'Send an estimate', 'inputSchema' => ['type' => 'object']],
        ]);
        $store = new MemoryLearningStore();
        for ($run = 0; $run < 5; ++$run) {
            $search = new ToolSearch($tools, learning: $store);
            $search->learn((string) $search->find(new SearchQuery('approving', 'estimate'))->searchId, 'approve_estimate');
        }

        // "approving" is no word of the tools, but it is close to "approve":
        // learned as "estimate" alone, it would lift the approval for every
        // request about an estimate, sending one too.
        self::assertArrayHasKey('approve estimate', $store->load());
        self::assertSame('send_estimate', self::names((new ToolSearch($tools, learning: $store))->search(new SearchQuery('sending', 'estimate')))[0]);
    }

    public function testThePairsOfCharactersOfOneWordAreNotLearnedAsAPair(): void
    {
        $tools = ToolSet::of([
            ['name' => 'owner_show', 'title' => '飼い主の詳細', 'description' => '飼い主の住所を表示します。', 'inputSchema' => ['type' => 'object']],
            ['name' => 'owners_list', 'title' => '飼い主一覧', 'description' => '飼い主の一覧を表示します。', 'inputSchema' => ['type' => 'object']],
        ]);
        $store = new MemoryLearningStore();
        for ($run = 0; $run < 5; ++$run) {
            $search = new ToolSearch($tools, learning: $store);
            $search->learn((string) $search->find(new SearchQuery('住所', '飼い主'))->searchId, 'owner_show');
        }

        // "飼い" and "い主" are both "飼い主" (owner): paired, they would lift
        // the tool asked for an address for every request about an owner,
        // listing them too. The verb and the object are still paired.
        self::assertArrayNotHasKey('い主 飼い', $store->load());
        self::assertArrayHasKey('住所 飼い', $store->load());
        self::assertSame('owners_list', self::names((new ToolSearch($tools, learning: $store))->search(new SearchQuery('表示', '飼い主')))[0]);
        self::assertSame('owner_show', self::names((new ToolSearch($tools, learning: $store))->search(new SearchQuery('住所', '飼い主')))[0]);
    }

    public function testTheFileStoreKeepsWhatWasLearned(): void
    {
        $file = self::temporary('learning') . '.json';
        (new FileLearningStore($file))->record(['ticket', 'ticket'], 'acme_get_ticket');
        (new FileLearningStore($file))->record(['ticket'], 'acme_get_ticket');

        self::assertSame(['ticket' => ['acme_get_ticket' => 2]], (new FileLearningStore($file))->load());
        unlink($file);
    }

    public function testAStoreKeepsItsMostUsedCountsWithinItsLimit(): void
    {
        $file = self::temporary('learning') . '.json';
        $words = array_map(static fn(int $i): string => 'word' . $i, range(1, Counts::LIMIT + 500));
        // Run twice, so they outlast the words run once.
        $often = ['often', 'often pair'];
        foreach ([new MemoryLearningStore(), new FileLearningStore($file)] as $store) {
            $store->record($often, 'acme_get_ticket');
            $store->record($often, 'acme_get_ticket');
            // In chunks, as runs of many searches would add them.
            foreach (array_chunk($words, 136) as $chunk) {
                $store->record($chunk, 'acme_get_ticket');
            }
            $store->record(['latest'], 'acme_get_ticket');

            $counts = $store instanceof FileLearningStore ? (new FileLearningStore($file))->load() : $store->load();
            self::assertLessThanOrEqual(Counts::LIMIT, array_sum(array_map('count', $counts)));
            self::assertSame(2, $counts['often']['acme_get_ticket'] ?? null);
            self::assertSame(2, $counts['often pair']['acme_get_ticket'] ?? null);
            self::assertSame(1, $counts['latest']['acme_get_ticket'] ?? null);
        }
        unlink($file);
    }

    public function testAFullStoreMakesRoomForManyPairsAtOnce(): void
    {
        $file = self::temporary('learning') . '.json';
        $words = array_map(static fn(int $i): string => 'word' . $i, range(1, Counts::LIMIT));
        foreach ([new MemoryLearningStore(), new FileLearningStore($file)] as $store) {
            foreach (array_chunk($words, 500) as $chunk) {
                $store->record($chunk, 'acme_get_ticket');
            }
            self::assertSame(Counts::LIMIT, self::total($store, $file), 'a store at its limit drops nothing');

            $store->record(['past the limit'], 'acme_get_ticket');
            self::assertSame(Counts::KEPT, self::total($store, $file), 'going past the limit cuts below it');

            // The room made is what spares a full store a sort at every run.
            $store->record(['next', 'pair'], 'acme_get_ticket');
            self::assertSame(Counts::KEPT + 2, self::total($store, $file), 'the next pairs fit without a cut');
            $counts = $store instanceof FileLearningStore ? (new FileLearningStore($file))->load() : $store->load();
            self::assertArrayHasKey('past the limit', $counts, 'the pair that went past the limit is kept');
            self::assertArrayNotHasKey('word1', $counts, 'the oldest of the least run went first');
        }
        unlink($file);
    }

    private static function total(MemoryLearningStore|FileLearningStore $store, string $file): int
    {
        $counts = $store instanceof FileLearningStore ? (new FileLearningStore($file))->load() : $store->load();

        return array_sum(array_map('count', $counts));
    }

    public function testAToolWithANumericNameIsFoundAndLearned(): void
    {
        $tools = ToolSet::of([
            ['name' => '123', 'description' => 'Export the backup archive', 'inputSchema' => ['type' => 'object']],
            ['name' => '42', 'description' => 'Remove a backup archive', 'inputSchema' => ['type' => 'object']],
            ['name' => 'list_files', 'description' => 'List the files', 'inputSchema' => ['type' => 'object']],
        ]);
        $learning = new MemoryLearningStore();
        $search = new ToolSearch($tools, new SearchOptions(), new MemoryIndexCache(), $learning);

        $result = $search->find(SearchQuery::text('export backup archive'));
        self::assertSame('123', self::names($result->tools)[0]);
        self::assertNotNull($result->searchId);

        $search->learn($result->searchId, '42');
        self::assertSame(['42', '123'], self::names($search->search('backup archive')), 'what was learned lifts a numeric name too');
    }

    public function testAWordTypedWithoutItsMarksFindsTheToolThatSpellsIt(): void
    {
        $tools = ToolSet::of([
            ['name' => 'file_upload', 'title' => 'Tải tệp lên', 'inputSchema' => ['type' => 'object']],
            ['name' => 'file_rename', 'title' => 'Đổi tên tệp', 'inputSchema' => ['type' => 'object']],
            ['name' => 'file_delete', 'title' => 'Xóa tệp', 'inputSchema' => ['type' => 'object']],
        ]);
        $search = new ToolSearch($tools);

        // Vietnamese typed on a phone: no tone marks, "d" for "đ".
        self::assertSame('file_delete', self::names($search->search(new SearchQuery('xoa', 'tep')))[0]);
        self::assertSame('file_rename', self::names($search->search(new SearchQuery('doi ten', 'tep')))[0]);
        // The other way round: the request has the marks the tool lacks.
        $unmarked = new ToolSearch(ToolSet::of([
            ['name' => 'folder_create', 'title' => 'Tao thu muc', 'inputSchema' => ['type' => 'object']],
            ['name' => 'folder_delete', 'title' => 'Xoa thu muc', 'inputSchema' => ['type' => 'object']],
        ]));
        self::assertSame('folder_delete', self::names($unmarked->search(new SearchQuery('xóa', 'thư mục')))[0]);
        // A letter of ASCII stands only for a Latin letter: a word of
        // another script is never spelled by one.
        self::assertSame([], self::names((new ToolSearch(ToolSet::of([
            ['name' => 'a', 'title' => 'Τέλος', 'inputSchema' => ['type' => 'object']],
        ])))->search('tlos')));
    }

    public function testAGreekWordTypedInCapitalsFindsTheToolThatSpellsIt(): void
    {
        // Greek in capitals carries no accent, and every capital sigma
        // lowers to "σ", where the tools end a word with "ς".
        $town = new ToolSearch(RecordingGate::server('city'));
        self::assertSame('parking_fine_appeal', self::names($town->search(new SearchQuery('ΕΝΣΤΑΣΗ', 'ΚΛΗΣΗ ΣΤΑΘΜΕΥΣΗΣ', 'ΗΤΑΝ ΣΩΣΤΑ ΠΑΡΚΑΡΙΣΜΕΝΟ')))[0]);
        $museum = new ToolSearch(RecordingGate::server('museum'));
        self::assertSame('exhibitions_list', self::names($museum->search('ΠΟΙΕΣ ΕΚΘΕΣΕΙΣ ΕΧΕΙ ΤΟ ΜΟΥΣΕΙΟ'))[0]);
        // Typed in haste, without accents.
        self::assertSame('tour_book', self::names($museum->search('θελουν ξεναγηση στα γαλλικα αυριο'))[0]);
    }

    public function testAHostStoreThatHandsBackANumberDoesNotBreakTheSearch(): void
    {
        $tools = ToolSet::of([
            ['name' => 'archive_export', 'title' => 'Export an archive', 'inputSchema' => ['type' => 'object']],
            ['name' => 'archive_delete', 'title' => 'Delete an archive', 'inputSchema' => ['type' => 'object']],
        ]);
        // The library never records a number alone, but a store of the host
        // may hold one, and PHP keys "2024" as an integer once decoded.
        $store = new class implements LearningStore {
            public function load(): array
            {
                /** @var array<string, array<string, int>> */
                return json_decode('{"2024": {"archive_delete": 3}, "archive export": {"archive_export": 2}, "\\tarchive export": {"archive_export": 2}}', true, 512, JSON_THROW_ON_ERROR);
            }

            public function record(array $words, string $tool): void {}
        };
        self::assertContains(2024, array_keys($store->load()));

        $found = (new ToolSearch($tools, learning: $store))->search(new SearchQuery('export', 'archive'));
        self::assertSame('archive_export', self::names($found)[0]);
    }

    public function testFiveHundredToolsAreSearchedFast(): void
    {
        $definitions = [];
        foreach (RecordingGate::fixtureTools() as $tool) {
            for ($copy = 0; $copy < 10; ++$copy) {
                $definitions[] = ['name' => $tool->name . '_' . $copy] + $tool->toArray();
            }
        }
        $tools = ToolSet::of($definitions);
        $query = new SearchQuery('change', 'ticket status', 'move a ticket to done');
        $cache = new MemoryIndexCache();
        $start = hrtime(true);
        (new ToolSearch($tools, cache: $cache))->search($query);
        $built = (hrtime(true) - $start) / 1e6;
        $start = hrtime(true);
        (new ToolSearch($tools, cache: $cache))->search($query);
        $cached = (hrtime(true) - $start) / 1e6;

        // Generous on purpose: CI machines are slow and shared.
        self::assertLessThan(500, $built);
        self::assertLessThan($built, $cached, 'the cache saves the build');
    }
}
