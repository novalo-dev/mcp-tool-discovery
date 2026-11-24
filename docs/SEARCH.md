# Search

`ToolSearch` finds the tools that answer a request, the best first. Every mode
reaches it through `ToolRunner::search()`, with the `SearchSetup` the host gave
`Discovery`; it can also be used on its own:

```php
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\ToolSearch;

$search = new ToolSearch($visibleTools);
$found = $search->search(new SearchQuery(action: 'change', object: 'ticket status', description: 'move the ticket to done'));
$found = $search->search('change the status of a ticket'); // free keywords still work
```

---

## A request in fields

A sentence mixes what to do, what it is done to and the details of one case.
Said in separate fields, each part can be weighed on its own:

| Field | Weight | Holds |
|---|---|---|
| `action` | 0.5 | what to do: "change", "list", "restore" |
| `object` | 1 | what it is done to: "ticket status", "backup" |
| `description` | 1 | the request in a short sentence |
| `text` (`query` in `search_tools`) | 1 | free keywords |

Each field is scored against the index on its own, then the scores are added
with these weights. No field is required and none is parsed: the library does
not know what a verb or an object is. The action counts least because many
tools share it ("list", "get"); the object and the description tell them apart.

On the benchmark below, the same requests find the right tool first 1120 times
out of 1476 in fields, and 831 times as one sentence.

## How a tool is scored

Each tool is a document of four fields, scored with BM25F:

| Field | Weight | Text |
|---|---|---|
| name | 1 | the name, split: `set_ticket_status`, `setTicketStatus` and `ticket.status.set` all give the same words |
| title | 2 | `title`, or the title of the annotations |
| description | 1 | `description` |
| parameters | 0.5 | the names and descriptions of the `inputSchema` properties |

BM25F gives each field its weight and normalises each field's length on its
own. A rare word weighs more than a common one: "rollback" counts more than
"file", which many tools mention, and a prefix every tool carries ("acme_")
counts almost nothing. That is why the library needs no list of stop words and
no rule to strip a prefix. A word repeated earns less each time.

Then the ranking is **cut** at 60% of the best score, and **limited** to 10
tools. Equal scores keep the host's order, so a query always gives the same
answer.

## Words and trigrams

The Analyzer splits snake_case, kebab-case, dots and camelCase, lowercases
(every script with mbstring, ASCII without it), keeps letters of any language
and leaves out numbers: "ticket 1042" is about a ticket, not about 1042.
A mark stays in the word it sits in: Hindi, Thai, Tamil and the other
Brahmic scripts write most vowels as marks ("सर्वर" holds three), Arabic and
Hebrew their vowel points, and text in decomposed form (NFD) every accent.
Unicode says which characters are marks; the library holds no list.

Each letter is lowered on its own, with Unicode's simple mapping, so a
request gives the same terms on every version of PHP. The full mapping
lowered the Turkish `İ` to an `i` and a combining dot, a word that never met
`izin`, and from PHP 8.3 it chose the Greek final sigma by context: a request
typed in capitals (`ΑΚΥΡΩΣΗΣ ΚΡΑΤΗΣΗΣ`) found other tools on 8.2 than on 8.3.
A capital sigma is now always lowered to `σ`, as PHP 8.2 did, and a word is
shown back as the tools write it, `ς` and all.

Each word also gives its trigrams, padded: "status" gives ` st`, `sta`, `tat`,
`atu`, `tus`, `us `. Trigram matches count at 0.6 of word matches. They let
"statuses" meet "status" and "categories" meet "category" with no rule of any
language.

**A word the tools do not use also counts as the tool words alike it.** The
trigrams of a request are pooled, so a word no tool holds meets any tool
sharing a piece of it: "reservation" meets "service" by `ser` and `vic` as
much as "reserve" by `res` and `ser`. Each tool word whose trigrams are alike
the whole word (a Dice coefficient of 0.3 at least, the threshold learning
already uses) now also counts as a word match, at half the weight and by how
alike it is, on top of the trigrams. Chosen on the train half: from 0.25 to 1
the requests in fields moved by 1 to 3 first places, as one sentence they
gained 17 to 33, most around 0.5 to 0.75; 0.5 was kept, which also gave the
most in the top 3. On the test half, the requests in fields go from 879 to
883 first (1023 in the top 3, unchanged), as one sentence from 600 to 628
(793 to 825), learned from the other half from 883 to 884, asked again 961
(1059 to 1060 in the top 3). In fields, 10 test requests come first that did
not and 6 no longer do, where the word alike in form is not the one meant.
In place of the trigrams of
those words, rather than on top, it lost 25 first places on train: rejected.
A cached search over 150 tools takes 0.27 ms against 0.25 ms.

**A word typed without its marks counts as the tool word it spells.**
Vietnamese, Polish and many languages written in Latin letters are often
typed without their marks, on a phone above all: "hoc phi" for "học phí",
"doladuj" for "doładuj". Trigrams do not see it, since the marks sit in short
words and change most of their trigrams. Folding a letter to its base
needs a table of letters or the intl extension, neither of which the
library has; reading where the marks are needs only the Unicode script of a
character, which PCRE knows. A word no tool uses counts as each tool word of
the same length that has the same letter wherever both are in ASCII or both
outside it, and, wherever one is a Latin letter outside ASCII (`ệ`, `đ`,
`ł`), any letter of ASCII in the other, either way round; the letters that
match outright must be at least as many as those taken on trust, so `don`
is not taken for `đón`, two of whose three letters it would take on trust. It counts as a word
alike at a Dice coefficient of 1, half the weight of the word itself, as the
words alike in form above. A letter stands only for a letter of its own
script: a Greek or Cyrillic word is never spelled by a word of ASCII.
Greek is read the same way (below), and Cyrillic not at all. Measured on the
train half with the kindergarten in: added on top of the words alike, at a
weight of 0.5, 0.75, 1 and 1.5, it found 1224, 1222, 1221 and 1220 first
against 1223, and as a word alike, 1224 and 955 as one sentence against 954:
kept. On the test half, the requests in fields go from 1089 to 1099 first
(1280 to 1291 in the top 3), as one sentence from 798 to 807, learned from
the other half from 1092 to 1102, asked again from 1198 to 1206: 9 on the
kindergarten, 1 on the bike rental, no server loses a request. The
places of the marks are read from the index at the first request that holds
a word no tool uses, and the tool words of the same length are first sifted
by one pass of PCRE that holds each letter to what may stand for it, so the
letters are only counted for the few that pass: a cached search over the
kindergarten takes 0.15 ms against 0.22 ms when each word was counted one by
one, over the bike rental 0.13 ms against 0.18 ms, and over the English
servers no more than before. Over three thousand tools whose words all have
eight letters, 32 words with marks that spell none of them cost 0.07 s
against 0.5 s; every ranking of the benchmark is the same.

**Chinese and Japanese are cut into pairs.** They are written without
spaces, so a whole sentence would be one word, and most of their words are
two characters long, too short to give a trigram of their own: "报修" (to
report a repair) inside "帮我报修" would never meet the tool. A run of Han or
kana is cut into overlapping pairs of characters, as CJK search has done for
decades (the bigrams of Lucene's CJK analyzer): "帮我报修" gives `帮我`, `我报`,
`报修`. No dictionary, no list: the scripts are Unicode's. The caller is
still shown the words it wrote, never the pairs: `unmatched` holds "帮我报修"
when no pair of it meets a tool, and `vocabulary` holds whole titles. Host
synonyms match a token or a pair, so `'派单' => '派工'` works inside a sentence.

**Thai, Lao and Burmese are cut into pairs of what is seen.** Thai is written
without spaces too, but its vowels and tone marks are marks, so a pair of code points would
hold a consonant and the vowel it carries, a fragment that means nothing. A
run of Thai is cut into overlapping pairs of grapheme clusters (`\X`, a
consonant with its marks): "ยกเลิก" (to cancel) gives `ยก`, `กเ`, `เลิ`,
`ลิก`. Unlike Chinese, the whole run is kept beside its pairs, and its
trigrams with it: on the train half of the sports centre, keeping it found
as many tools first, with fewer tools returned. Khmer is written the same
way and is read as before, its runs met by their trigrams: on the train half
of the water supply office (below), cutting it into pairs of clusters as Thai
is found as many tools first, returned more, and learning lost one more
request. Burmese, measured on the rice mill (below), is cut as Thai is:
on its train half, pairs kept beside the run found 45 of 50 tools first
against 43, and 39 as one sentence against 36, with fewer tools returned
(1.9 against 2.0); pairs alone found 44. Lao, measured on the post office
(below), is cut as Thai is too: on its train half, pairs kept beside the run
found 47 of 52 tools first against 42; pairs alone found 47 as well, but
learning from the other half then lost two.

**A Korean word is cut into pairs.** Korean puts spaces between words, but
glues to each word the particles that mark its role: "레슨을 취소해 줘"
(cancel the lesson) holds "레슨을", the lesson as object, never "레슨", the
word the tool writes, and a word of two syllables gives too few trigrams to
meet it. Each word of Hangul is cut into overlapping pairs of syllables, as
Lucene's CJK analyzer does: "레슨을" gives `레슨` and `슨을`. Unlike Thai, the
word is not kept beside its pairs: on the train half of the music school,
keeping it found fewer tools first (38 against 39 in fields) and returned
more. As for Chinese, the caller is shown the words it wrote, not the pairs.

**No stemmer, on purpose.** A Porter stemmer was measured and made the search
worse: folding "categories" to "categori" erases the sign that a plural query
wants the list tool, and more tools tie.

## Words to say it again

`find()` returns, next to the tools, the words of the request that no tool
uses. `search_tools` then adds `vocabulary`: the words the visible tools'
titles use most, computed from those tools, leaving out a word found in most
titles. A client that asked for a "meeting" on a server that says "event" sees
"event" and asks again.

## Synonyms and stop words

The library holds no business vocabulary. A host that knows its clients say
"meeting" for a calendar event gives a map, and words to ignore if it wants:

```php
use Novalo\McpToolDiscovery\Search\SearchOptions;

new SearchOptions(synonyms: ['meeting' => 'calendar event', 'admin' => 'role'], stopWords: ['please']);
```

The synonyms are added to each field of the request; the caller's own words
still count. Better still, put those words in the tool descriptions: every
client reads them, even one that never searches.

---

## Cache

Building the index is most of the cost of a search. An `IndexCache`, given
once in the `SearchSetup`, keeps it for every mode:

```php
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;

new SearchSetup(cache: new MemoryIndexCache());        // a long-running server
new SearchSetup(cache: new FileIndexCache($cacheDir)); // each request starts afresh
```

The key is a hash of every text indexed and of the settings. Two callers who
see different tools get two indexes, so an index never holds a tool hidden
from the one searching it; a changed tool gives a new key, so nothing goes
stale. `FileIndexCache` writes PHP files that return an array: OPcache keeps
them compiled in shared memory. Its directory must be one only the server
writes to. The files of tools that have since changed stay there; emptying the
directory when the server is deployed is safe
([When the tools change](HOSTS.md#when-the-tools-change)).

| Tools | No cache | Memory | Files, with OPcache |
|---|---|---|---|
| 50 | 1.5 ms | 0.1 ms | 0.1 ms |
| 150 | 5 ms | 0.3 ms | 0.3 ms |
| 500 | 16 ms | 1.1 ms | 1.2 ms |

A search with the three fields, on one machine, the median of 200; the times
include the request.

The index is inverted: each word and trigram points to the tools that hold
it, with its weight there already worked out from the fields and their
lengths. A search then visits only the tools that share a term with it.
Before, it read every tool for every term, and a cached search over 500 tools
took 6 ms; the ranking is the same to the last digit. The saved index is
about a third larger (220 KB for 50 tools in `FileIndexCache`), and a process
holding it uses less memory than before.

Without a cache, a third of the build was cutting words into trigrams, once for
each field a word appears in. Each word is now cut once per build, and a word
in ASCII is cut by bytes, which is several times faster than splitting it into
characters: a build over 500 tools went from 25 ms to 16 ms, and the index is
the same to the last digit.

## Learning

Off by default. With a `LearningStore` in the `SearchSetup`, the search learns
which tool answered which words. It is wired in the core, in `ToolRunner`, so
every mode learns the same way and none can skip the checks:

1. A search returns a `search_id`: the words of the request that the tools
   use, and only those. A word they do not use stands for the tool word
   closest to it, when one is close enough (below).
2. The client runs the tool it chose with that `search_id`.
3. If the run succeeds, each of those words, and each pair of them, counts
   one more run for that tool.

Next time, a tool is lifted by the share of the request's pairs of words that
led to it (its single word, for a request of one word), each pair counting
fully after `learningCap` (5) runs, by at most `learningWeight` (0.75) of the
best score. That share is then multiplied by the share of the request's
pairs that reached the tool at all: a request that agrees with a learned
one on one pair of three lifts its tool a ninth as much as the same request
asked again, which keeps every pair. Neighbours share pairs without the
verb: "add a site address", run once, lifted the tool that creates a site
before the one that changes an address for "change a site address", with
the plain mean of the pairs.

Last, the lift is multiplied by the square of the largest share of the
words of one search learned for that tool that the request holds. Each run
also keeps its whole search, its words in alphabetical order, under a key
that starts with a tab, which no word or pair can hold. The share of pairs
reads the request, not the search learned: "view the instruments free to
lend", run once for the list, taught it the pair "instrument lend", which is
the only pair of "lend an instrument, a cello", so the list took the place
of the loan. The request holds two of the three words of that search, so the
list keeps four ninths of its lift, and the loan comes first again; the same
search asked again holds all its words and keeps its whole lift. A tool
learned before whole searches were kept has none, and keeps its lift as
before. Chosen on the train half, in two folds: learning from one fold to
the other found 1178 first against 1181 without learning, and 1182 with the
square of the share (1180 with the share, 1181 with its cube, 1178 to 1181
with a Dice coefficient of the two searches instead); asked again, 1239 to
1245. On the test half, read once: learned from the other half, 1072 to
1074, so learning no longer costs the airline operations centre its first
place, and asked again, 1171 to 1175; no server loses a request. The whole
searches are found by reading every key of the store, as loading it has
already done: a search that learns over the 176 hosting tools, with every
request of that server learned, takes about 0.55 ms against 0.35 ms.

Pairs, not single words, because of servers with many tools about the same
thing. Word by word, "get the certificate" run once taught "certificate" to
the tool that shows a certificate, and "delete the unused certificate" then
found it before the tool that deletes one. A pair is only shared by requests
that agree on more than the object. On the hosting server, word by word,
learning from the train half cost 3 test requests their first place; by
pairs, it costs none. A store filled word by word before this keeps working
for requests of one word, and learns the pairs from the next runs. The pairs
of a request are taken from its first 16 words in alphabetical order, so a
long request costs at most 120 keys.

Two pairs of characters of one word are not a pair. Japanese, Chinese,
Korean, Thai, Lao and Burmese are cut into pairs of characters (above), so one word of a
request gives several terms: "飼い主" (owner) gives "飼い" and "い主". Paired
with each other, they only said the object again, and a search for an
owner's address then lifted that tool for every request about an owner. When
a request has several tokens (its fields, the words of a Korean sentence),
only terms of different tokens are paired, so the verb still pairs with its
object; a sentence without spaces is one token, and keeps every pair of its
terms. A word of an alphabet is one term, so nothing changes for it. The
rule was chosen on the train half of the Japanese veterinary clinic
(below), in two folds: learning from one fold to the other cost 2 first places, and
now costs none; pairing only terms that never share a token did better
there than skipping only terms of one token alone (1 lost). The train half
of the other servers does not move. On their test half, the sports centre
gains one first place after learning from the other half (586 to 587 in
all) and one asked again (637 to 638), with nothing lost. A `search_id` now keeps the
terms of each token together; an id of single words, as before, reads as one
token per word and learns as it did.

The limits are the point:

- **Only words the tools use are kept** (a word they do not use, as the tool
  word closest to it), checked again when the run comes back,
  so a store never holds a name, an address or anything a person typed beyond
  the server's own vocabulary.
- **It reorders, it never adds**: a tool the request does not describe is never
  brought in by what was learned.
- **Only visible tools**: a run of a tool the caller may not see teaches
  nothing, and a `search_id` made up by the client can at most lift a visible
  tool for words it already holds, within the cap.
- **One store per server**: two servers with different tools need two stores.

A word written another way is learned as the tool word closest to it.
The word that tells two neighbours apart is often the verb, and a request
rarely writes it as the tool does: "أرسل" (send!) where the tool says "يرسل"
(it sends), "prêter" where it says "prêt", "approving" for "approve". It
found the tool by its trigrams, but it was left out of what was learned, so
a search only taught its object, which the neighbours share: on the Arabic
workshop, approving an estimate then lifted the approval for the next request
to send one, and learning from the train half cost two test requests their
first place. Each such word is now kept as the indexed word whose trigrams
are most alike (a Dice coefficient of 0.3 at least), so the store still
holds only the tools' words, and the object is paired with what is done to
it again. Learned from the other half, the test half gains 3 first places
(546 to 549) and loses none; asked again, two more come first and one
fewer (594 against 593). On the train half, in
two folds, learning from one fold to the other went from 7 lost first places
to 4. The threshold was chosen on those folds: 0.2 to 0.5 all gained, 0.2
by one request asked again; 0.3 was kept, the stricter, so fewer words are
taken for a tool word they only half resemble. The closest word is only
looked for when the host learns, and only for words the tools do not use. It
reads the trigrams of the tool words inverted, each pointing to the words
that hold it, so only the words sharing a trigram are compared. With an
`IndexCache`, they are built with the index and kept with it, since the
search reads them too (see "Words and trigrams"): the saved index is about a
quarter larger. Cutting every tool word again at each request made a
search that learns over 176 tools take more than 6 ms, ten times the search
itself; it now takes about 0.6 ms against 0.4 ms, and 1.9 ms against 1.8 ms
over 706 tools, the closest word being the same for each of the 15 944 words
of the benchmark's requests.

`MemoryLearningStore` lasts the process; `FileLearningStore` keeps a JSON file,
locked while it is written.

---

## Log

What the benchmark cannot say is what a host's own clients ask. A `SearchLog`
in the `SearchSetup` is told each search a mode runs, with its answer, and
each run of a tool after it:

```php
use Novalo\McpToolDiscovery\Search\SearchLog;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\SearchResult;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;

final class JsonLinesLog implements SearchLog
{
    public function __construct(private readonly string $file) {}

    public function searched(SearchQuery $query, SearchResult $result): void
    {
        $this->write(['search' => $result->searchId, 'fields' => $query->fields(), 'found' => array_map(static fn(Tool $tool): string => $tool->name, $result->tools), 'unmatched' => $result->unmatched]);
    }

    public function ran(Tool $tool, bool $succeeded, ?string $searchId): void
    {
        $this->write(['search' => $searchId, 'ran' => $tool->name, 'succeeded' => $succeeded]);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function write(array $line): void
    {
        file_put_contents($this->file, json_encode($line) . "\n", FILE_APPEND | LOCK_EX);
    }
}

new SearchSetup(log: new JsonLinesLog($logFile));
```

- **A search and its run share an id.** With a log, a mode hands out a
  `search_id` even when nothing is learned, and the run that carries it back
  is logged with it: a search whose first tool is not the one that ran, or
  that found nothing, is a miss to read.
- **Only what the caller may see.** A run is logged once the host's gate has
  shown and authorized the tool, never before, and a search only ever holds
  the visible tools. A `search_id` the client sends back is passed on only
  if a search could have written it: base64url, 2048 characters at most;
  otherwise the run is logged with none.
- **The host keeps it.** The library writes nothing. The words of a request
  can hold what a person wrote, so what is kept, where and for how long is
  the host's choice. Both calls are made during the client's request: keep
  them cheap, and do not throw, as an exception fails the call.

Read together, the lines show the words to add to the host's synonyms, the
titles to write again, and the requests worth adding to the benchmark.

The stdio example keeps such a log when `MCP_NOTES_SEARCH_LOG` names a file
(`NotesSearchLog`, in [examples/notes.php](examples/notes.php)). A search for
"open a note" by a reader, then the note read with its `search_id`:

```
{"at":"2026-11-23T09:26:36Z","search":"YSBub3Rl","fields":{"text":"open a note"},"found":["notes_add","notes_get"],"unmatched":["open"]}
{"at":"2026-11-23T09:26:37Z","search":"YSBub3Rl","ran":"notes_get","succeeded":true}
```

The tool that ran is the second one found, and "open" is a word no tool
uses: a synonym of "read" for this host.

[examples/search-log-report.php](examples/search-log-report.php) reads such a
log and prints those leads, most frequent first: the words no tool uses with
the tools that ran after them, the runs of a tool the search did not put
first, and the searches that found nothing. On the two lines above:

```
php docs/examples/search-log-report.php search.log
1 searches, 1 runs (0 failed), 0 unreadable lines
0 of 1 successful runs after a search ran the first tool found

Words no tool uses, and what ran after them
  open                    1  notes_get (1)

Runs of a tool the search did not put first
  notes_get            2nd           1  text: open a note

Searches that found nothing
  (none)
```

A failed run is counted but ranks nothing, since it says more about its
arguments than about the search, and a line cut short is counted and
skipped.

---

## The measures

Twenty-five fictional servers: a workspace (50 tools, `acme_set_ticket_status`), a
developer platform (40 tools, `archiveRepository`), people operations (40
tools, `people.leave.approve`), a network of public libraries written in
French (40 tools, `prolonger-pret`), the office of a school written in German
(40 tools, `KlassenfahrtAbsagen`), a cloud hosting panel (176 tools,
`nimbo_detach_volume`), the service desk of a town hall written in Greek
(40 tools, `parking_permit_renew`), a newsroom with no titles and long
descriptions (40 tools, `schedule_article`), the office of a residential
estate written in Chinese (40 tools, `repair_assign`), a bus depot
written in Hindi (40 tools, `pass_renew`), a sports centre written in Thai
(40 tools, `court_book`), a music school written in Korean (40 tools,
`lesson_move`), a car repair workshop written in Arabic (40 tools,
`estimate_send`), the front desk of a hotel written in Russian (40 tools,
`BOOKING_CHANGE_DATES`), a city theatre written in Ukrainian (40 tools,
`performance_move`), a marina written in Hebrew (40 tools,
`Berth-Extend`), a veterinary clinic written in Japanese (40
tools, `appointment_move`) a water supply office written in Khmer (40
tools, `outage_schedule`), a post office written in Lao (40 tools,
`parcel_redirect`), a rice mill written in Burmese (40 tools,
`paddy_receive`), an archaeological museum written in Greek (40 tools,
`loan_approve`), a city bike rental written in Polish (40 tools,
`bike_unlock`), an airline operations centre (150 tools,
`flight.change-aircraft`), a fleet of field sensors generated from
OpenAPI (40 tools, `post_devices_device_id_reboot`), a ferry line
written in Turkish (40 tools, `ticket_refund`) and a kindergarten written
in Vietnamese (40 tools, `absence_notice_cancel`). 2945 requests, each written as one
sentence and in fields, with the tool a person would expect.
The settings were chosen on the `train` half; the `test` half was kept aside
and gives the score.

| Test half, 1476 requests | Right tool first | Top 3 |
|---|---|---|
| The request in fields | 1120 | 1314 |
| The same request as one sentence | 831 | 1080 |
| In fields, after every train request was run once | 1123 | 1314 |
| In fields, after the test requests were run once, then asked again | 1229 | 1371 |

The same requests ranked by the search of laravel/mcp, Laravel's official MCP
package, which a PHP host may already have: a substring score on the name,
the description and the input schema, with no title, no index and no
learning (`tests/Fixtures/SubstringSearch.php`). It takes one string, so the
fields are joined into one:

| The same half, ranked as laravel/mcp v1.2.1 ranks | Right tool first | Top 3 |
|---|---|---|
| The request as one sentence | 436 | 672 |
| The fields joined into one sentence | 806 | 1043 |

As one sentence it trails on each of the twenty-six servers, the English ones
included, and finds nothing first on the Chinese and Japanese ones: it cuts
words at spaces, which those languages do not write. The test that guards
the floors also checks that the library stays ahead on both lines.

The library and school servers came after the settings were chosen, each
with its 100 requests written before any of them was run, so they measure
what the search does on a server nobody tuned it for: 39 and 38 of their 50
test requests find the right tool first in fields, 43 and 44 in the top 3,
against 125 and 140 of 150 on the other three. The search reads French and
German as it reads English, with no list and no stemmer. On the library, what
it misses is mostly conjugation ("prêter" asked, "prêt" in the tool) and an
object that names the kind of item ("livre", "DVD") and so finds the tool
that lists those kinds in its description. On the school, it is mostly words
the tools do not use ("Unterricht" for the timetable, "Abwesenheit" for what
the tools call a "Fehlzeit"), and a short word inside an unrelated one: "Buch"
(a book) shares its trigrams with "buchen" (to book), so a request to lend a
book finds the tool that books a meeting with parents first.

The hosting server is the crowd: 176 tools, most of them sharing an object
with five or ten others (start, stop, power off, reboot, resize, rebuild a
server; snapshot, back up, restore), as on a real server that exposes its
whole back office. Its 197 requests were written before any of them was run,
many of them on purpose between two neighbours. The weights and the cut at
60% were not touched for it, and they hold: 84 of its 99 test requests find
the right tool first in fields, 94 in the top 3. What it misses is mostly the
neighbour of the same object ("drop the old MySQL cluster" finds the tool that
creates one, the word "cluster" being in both), and a few words the tools do
not use ("machine" and "VM" where the tools say "server").

The town hall is another script: Greek titles and descriptions, English
snake_case names, so no word of a request ever meets a tool's name. Its 100
requests were written before any of them was run, and about half of them are
typed as many people type Greek, without the accents (`ακυρωσε` for
`ακύρωσε`). Nothing was tuned on it: 41 of its 50 test requests find the
right tool first in fields, 47 in the top 3. The accents are where it loses:
24 of the 26 test requests written with them come first, 17 of the 24
written without. A word typed without its accent was another word to the
index, met only by its trigrams away from the accent (`εκδηλωση` asked,
`εκδήλωση` in the tool), until it counted as the tool word it spells
(below).

The newsroom is written as many real servers are, and none of the others
is: no tool has a title, so its name stands in for one, and each description
runs 18 to 70 words, opens with "Use this tool to", and points at its
neighbours ("unpublish it first", "use compare_article_revisions"). Its 100
requests were written before any of them was run, and nothing was tuned on
it: 35 of its 50 test requests find the right tool first in fields, 46 in the
top 3, and 30 as one sentence, closer to the fields than on any other server,
since long descriptions hold more of a sentence's words. What it misses is
mostly words the tools do not use ("sign off" for approve, "journalist" for
an author, "hide" for unpublish), and a word that a neighbour's description
uses in passing: "versions" finds the tool that edits a draft, whose
description says earlier versions can be restored, before the tool that
lists them. Learning from its train half costs it one request (34 first),
as on the school; the total still gains one.

The estate is written in Chinese, with English snake_case names: a script
with no spaces between words. Its 100 requests were written before any of
them was run, in the words of a building manager ("派单" for to assign, "工单"
for a repair ticket, "出账" for to bill). Before the pairs of characters,
30 of its 50 test requests found the right tool first in fields and 17 as one
sentence, since a sentence was a single word that met tools by trigrams
only. Cut into pairs, chosen on its train half, it finds 40 first in fields
(45 in the top 3) and 26 as one sentence (34 in the top 3); the eight other
servers did not move by one request. Two variants lost on train: keeping the
whole run beside its pairs (30 first as one sentence, against 34), and
dropping the trigrams of the pairs (the same scores, more tools returned).
What it still misses is mostly words the tools do not use ("撤回" for to take
down a notice, "值班" for a shift) and a list beside its single tool
("查看报修状态" finds the list of repairs first).

The depot is written in Hindi, with English snake_case names: a script
spaced like English, but whose vowels are marks. Its 100 requests were
written before any of them was run, as staff at a depot talk, with the
English words Hindi has taken in ("ट्रिप", "कैंसिल"). Until marks were kept
in their words, every vowel cut a word in two, and the fragments ("सर" out
of "सर्वर") met unrelated tools: 36 of its 50 test requests found the right
tool first in fields and 15 as one sentence, with nearly four tools returned
in fields and five as one sentence. Kept whole, 39 come first in fields (45
in the top 3) and 23 as one sentence (33 in the top 3), with about two
tools returned in fields and three as one sentence; the nine other servers
do not move by one request. Dropping the marks instead, so that a word with
its vowels and one without would meet, lost on train (38 first in fields,
against 42): with the vowels gone, too many words become the same.

The sports centre is written in Thai, with English snake_case names: a
script with no spaces between words and vowels written as marks. Its 100
requests were written before any of them was run, as front desk staff talk
("ช่วยจองสนามแบดพรุ่งนี้หกโมงเย็น", book a badminton court for tomorrow at
six). A sentence used to be one word that met tools by its trigrams only, and
it did better than Chinese did: 34 of its 50 test requests found the right
tool first in fields (47 in the top 3) and 30 as one sentence (41). Cut into
pairs of grapheme clusters, chosen on its train half, 37 come first in fields
(48 in the top 3) and 31 as one sentence (41), with about three tools
returned instead of two and a half; asked a second time, the whole test half
gains 10 first. The ten other servers do not move by one request. On train,
pairs of code points (43 first in fields, 35 as one sentence, against 44 and
39) and triples of clusters (43 and 39) lost. What it still misses is mostly
a list beside its single tool ("มีอุปกรณ์อะไรให้ยืมบ้าง", what equipment can
be lent, finds the tool that takes it back first).

The music school is written in Korean, with English snake_case names: a
script spaced like English, whose words carry their particles. Its 100
requests were written before any of them was run, as staff at the front
desk talk ("하준이에게 첼로를 빌려 줘", lend Hajun a cello). With each word
compared whole, 36 of its 50 test requests found the right tool first in
fields (40 in the top 3) and 34 as one sentence (41), and learning from the
train half lost one request from the top 3. Cut into pairs of syllables,
chosen on its train half, 37 come first in fields (44 in the top 3) and 36
as one sentence (41), with fewer tools returned (1.9 against 2.0 in fields,
1.9 against 2.4 as one sentence), and learning loses nothing. The eleven
other servers do not move by one request. On train, triples of syllables
with the word kept lost (37 first in fields, against 39). What it still
misses is mostly a word the tools do not use ("연주회", a concert, where the
tools write "발표회", a recital) and a list beside its single tool
("바이올린 몇 대 남았어", how many violins are left, finds the tool that lends
one first).

The workshop is written in Arabic, with English snake_case names: a script
spaced like English, whose words carry the article and other letters glued to
them ("الموعد", the appointment, where the tool writes "موعد"), and whose
verbs change shape ("أرسل", send!, against "إرسال", sending). Its 100 requests
were written before any of them was run, as staff at the desk talk, partly in
Gulf dialect ("وش السيارات اللي نشتغل عليها حاليا"). Nothing in the search was
changed for it: 32 of its 50 test requests find the right tool first in fields
(39 in the top 3) and 23 as one sentence (32), the trigrams doing what the
glued letters undo. Cutting each Arabic word into pairs of letters, as Korean
is, won on its train half (46 first in fields against 43, 44 as one sentence
against 39, the word kept beside its pairs) and lost on its test half (30
first in fields against 32), with more tools returned (3.3 against 2.6): it
was not kept, nor pairs alone (31 first), nor pairs kept apart from words of
two letters (30). Fifty requests are few, and the train half chose a variant
the test half did not confirm; trigrams stay. What it misses is mostly words
the tools do not use ("زبون" for a customer, where the tools say "عميل";
"التسعيرة" for an estimate) and neighbours of one object (cancelling a
booking finds the tool that books one).

The hotel is written in Russian, with SCREAMING_SNAKE_CASE names: a
script of its own whose words change their endings with their role
("бронь", "брони", "бронью" for a booking) and whose verbs change shape
("закрой", close!, against "Закрыть"). Its 100 requests were written before
any of them was run, as staff at the desk talk, and nothing in the search was
changed for it: 37 of its 50 test requests find the right tool first in fields
(47 in the top 3) and 23 as one sentence (29), the trigrams catching the
endings. It was written before the closest tool word was learned, and could
not join until then: learning from its train half cost one test request its
place in the top 3, a request whose only word the tools use was a
preposition ("на"), learned elsewhere. With the closest word learned, its
other words pair again, and learning loses nothing (37 and 47 both ways).
What it misses is mostly neighbours of one object (opening a room again after
repairs finds the tool that closes it) and words the tools do not use
("такси" for the transfer).

Twelve requests more on each half were written later, again before any of
them was run, typed as Russian is most often typed: with "е" where the tools
write "ё" ("счет" for "счёт", "отчет", "черный список", "платеж"). On the
test half, 11 of the 12 come first in fields as they stood; the short words
are the ones the trigrams miss, as most trigrams of "счет" differ from those
of "счёт".

The clinic is written in Japanese, kanji, hiragana and katakana with no
spaces, behind snake_case names: a veterinary clinic whose tools are
neighbours by design (book, move, cancel an appointment; prescribe, refill,
cancel a prescription; admit and discharge). Its 100 requests were written
before any of them was run, as staff at the desk talk, and the pairs of
characters were never measured on Japanese before it. Nothing in the
weights or the cut was changed for it: 33 of its 50 test requests find the
right tool first in fields (37 in the top 3) and 26 as one sentence (35).
It could not join at first: learning from its train half cost 3 test
requests their first place, the pairs of characters of one word being
learned as a pair (above); with terms paired across tokens only, learning
loses nothing (33 and 37 both ways). What it misses is mostly words the
tools do not use ("ツケ" for an unpaid bill, "補充" for ordering supplies) and
neighbours of one object (moving an appointment finds the tool that cancels
it).

The water supply office is written in Khmer, behind snake_case names: a
script with no spaces between words, only between phrases, whose vowels are
marks and whose consonants stack below one another. Its tools are neighbours
by design (cut and reconnect the water; issue, void and correct a bill;
schedule, cancel and announce an outage). Its 100 requests were written
before any of them was run, as staff at the office talk, and nothing in the
search was changed for it: 32 of its 50 test requests find the right tool
first in fields (39 in the top 3), 32 as one sentence (42), and learning
loses nothing. A phrase of Khmer is one token that meets tools by its
trigrams, as Thai once did: the right tool is found, but less sharply, with
about three tools returned against two on most servers. On its train half,
pairs of grapheme clusters, kept beside the run as for Thai, found as many
tools first (42) and as one sentence one more (38), but returned more tools
(2.7 against 2.4 as one sentence) and learning lost two requests against
one; pairs alone found 43 first and 48 in the top 3 against 49, and pairs of
code points 41. None was kept. What it misses is mostly neighbours of one
object (cancelling an outage finds the tool that schedules one) and words the
tools do not use ("ពាក្យសុំ", an application form, where the tools write
"សំណើ", a request).

The rice mill is written in Burmese, behind snake_case names, with spaces
between phrases only, vowels and tones written as marks and consonants
stacked with a virama. Its tools are neighbours by design (receive, correct
and cancel a delivery of paddy; pay a farmer and void the payment; record and
cancel a sale of rice; grant and repay an advance; book and cancel a truck).
Its 100 requests were written before any of them was run, most of them as
one run with no space at all. Its train half chose one thing, how Burmese is
cut (above); no weight or cut changed for it. On its test half, read by its
trigrams as before, 36 of 50 requests found the right tool first in fields
and 42 in the top 3, with 2.42 tools returned, and learning lost one. Cut
into pairs of clusters, 36 come first and 45 in the top 3, with 2.36 tools
returned; 31 as one sentence (38 in the top 3, one less than before), and
learning loses nothing (36 and 45 both ways). What it misses is mostly words
the tools do not use ("တောင်သူ" for a farmer, where the tools write
"လယ်သမား"; "ချေးငွေ", a loan, where they write "ကြိုတင်ငွေ", an advance)
and neighbours of one object (selling rice finds the tool that cancels a
sale).

The museum is written in Greek, the second server in that script, behind
snake_case names. Its tools are neighbours by design (register, correct, move
and strike off a find; request, approve and return a loan; book, cancel and
move a guided tour), and its 100 requests were written before any of them was
run: about a quarter typed without accents, as on the town hall, and one in
eight in capitals, as copied from a form or the subject of an e-mail. Nothing
was tuned on it: 38 of its 50 test requests find the right tool first in
fields, 48 in the top 3, and 16 as one sentence; learned from its train half,
40 come first, and learning loses none.
It is what showed that PHP 8.2 and 8.3 lowered its capitals differently
(above): with each letter lowered on its own, 16 come first as one sentence
on every version, against 17 on 8.3 and 16 on 8.2 before. What it misses is
mostly accents again (`συντηρησεις` asked, `συντήρηση` in the tool) and
neighbours of one object (asking for a loan finds the list of loans).

Twelve requests more on each of the two Greek servers were written later,
again before any of them was run, typed as Greek is typed in capitals or in
haste: with no accent at all, and so with a plain `σ` where the tools end a
word with `ς` (`ΚΡΑΤΗΣΕΙΣ` lowers to `κρατησεισ`, never to `κρατήσεις`).
Half of them went to each half; on the test half, 10 of the 12 come first
in fields as they stood.

**A Greek word typed without its accents counts as the tool word it
spells**, as a Latin one does (above): the plain Greek letters are the one
range α to ω, so a letter of that range stands for any other Greek letter
(`ή`, `ϊ`, `ῶ`), and the other way round, with no table. A sigma also
stands for a final sigma: Unicode lowers a capital sigma to `σ` wherever it
stands, so `ΚΡΑΤΗΣΕΙΣ` reaches `κρατήσεις` only that way. Tried on
2026-11-24 without the sigma, it lost a test request typed in capitals, and
was put aside until requests of that kind were on the train half. With
them, on the train half: 1235 to 1236 first in fields (1352 to 1354 in the
top 3), 967 to 968 as one sentence, 1303 to 1305 asked again, learning from
one fold to the other 1236 to 1237. On the test half, read once: 1109 first
in fields and 1303 in the top 3 as before, as one sentence 815 to 820 (1065
to 1069 in the top 3), learned from the other half 1112 as before, asked
again 1217 to 1218; every ranking of the other servers is the same. A
cached search over the town hall or the museum takes about 0.25 ms against
0.14 ms, since their words now go through the spelling too.

The bike rental is written in Polish, the first Latin script whose letters
are typed away as often as its accents: "zglos" for "zgłoś", where no
decomposition gives "ł" a base. Its tools are neighbours by design (lock and
unlock a bike, block and unblock an account, close and reopen a station,
issue and waive a penalty), and its 100 requests were written before any of
them was run, alternating with and without the Polish letters, so that each
half holds both (27 of the train half and 30 of the test half are plain
ASCII). Nothing was tuned on it: 39 of its 50 test requests find the right
tool first in fields, 46 in the top 3, 31 as one sentence, with 2.24 tools
returned; learned from its train half, 39 and 46 again. Long Polish words
keep enough trigrams to meet without their letters ("rowerow" finds
"rowerów"); short ones did not ("doladuj" against "doładuj") until a word
typed without its marks counted as the tool word it spells (above): 40
first in fields, 36 as one sentence. What it misses now is mostly
neighbours of one object (closing a lock remotely finds the
tool that opens it).

The airline operations centre is the size and shape of a real server: 150
tools in English, named by a dotted object and a hyphenated action
(`flight.change-aircraft`, `crew.call-out`), most of them neighbours on one
object (delay, cancel, reinstate and divert a flight; assign, unassign and
swap a crew member; block and unblock a gate or a seat). Its 300 requests,
two per tool, were written before any of them was run, and it is the first
server split as "Changing the ranking" says: the first request of half its
tools is in each half. Nothing was tuned on it: 117 of its 150 test requests
find the right tool first in fields, 126 in the top 3, 98 as one sentence;
learned from its train half, 121 come first and none is lost from the top 3;
asked again, 122. Its train half scores 111 in fields, so both halves are as
hard. What it misses is the two kinds below, at this size: neighbours of one
object ("reopen" a gate finds the tool that blocks it, "release" a repaired
aircraft finds the dispatch release of a flight, "move down" a traveller
finds the upgrade) and words its tools do not use ("reservation" for a
booking, "luggage" for a bag, "plane" for an aircraft), where trigrams then
meet the wrong word ("reservation" lands on "reserve" and "service").

The city theatre is the second server written in Cyrillic, in Ukrainian,
whose words change their endings as Russian ones do and some carry an
apostrophe inside (прем'єра, сім'я) that cuts them in two. Most of its 40
tools are neighbours on one object (schedule, move and cancel a
performance; book a seat, release a booking, sell, refund and exchange a
ticket; hire, dismiss and cast an actor). Its 100 requests were written
before any of them was run and split as the airline's. Nothing was tuned on
it: 40 of its 51 test requests find the right tool first in fields, 46 in
the top 3, 37 as one sentence; learned from its train half, 40; asked
again, 44. Its train half also scores 40 of 49 in fields. What it misses is
neighbours again ("find" a booking by name finds the tool that releases
one, "take on" an actor finds the list of the company, ending an actor's
contract finds the hiring, whose contract field shares the word) and a
word a neighbour owns ("розклад", a schedule, of the performances finds the
schedule of the rehearsals).

The marina is written in Hebrew, which glues its particles to the start of
a word ("במרינה", in the marina; "לסירה", to the boat; "מהמים", from the
water) where every other server changes the end. Its names are an object
and an action in Train-Case (`Berth-Reserve`), a style no other server
uses, and most of its 40 tools are neighbours (reserve, release, extend and
move a berth; open and close a repair; close and reopen the harbour). Its
100 requests were written before any of them was run and split as the
airline's. Nothing was tuned on it: 40 of its 50 test requests find the
right tool first in fields, 46 in the top 3, 37 as one sentence; learned
from its train half, 40; asked again, 42. Its train half also scores 40 of
50 in fields, but 28 as one sentence. The particles cost little: a word
with one keeps most of its trigrams, so it is already alike the bare word,
and taking a word that ends with a tool word for that word, tried on the
train half, moved nothing in fields. What it misses is neighbours ("end"
a contract finds its renewal, "finish" a repair finds its opening) and
words the tools do not use ("יאכטה", a yacht, for a boat).

The post office is written in Lao, behind snake_case names: as Thai, with
no space between words, a space between phrases, and vowels and tone marks
written as marks around a consonant ("ພັດສະດຸ", a parcel). Most of its 40
tools are neighbours on one object (send, track, hold, return and redirect
a parcel; send, cancel and pay out a money transfer; rent, renew and close
a post office box; open and close a complaint or a counter). Its 100
requests were written before any of them was run and split as the
airline's, 52 in train and 48 in test. Its train half chose one thing, how
Lao is cut (above); no weight or cut changed for it. On its test half, read
by its trigrams as before, 33 of 48 requests found the right tool first in
fields and 43 in the top 3, with 2.48 tools returned, 26 as one sentence,
and 37 asked again. Cut into pairs of clusters, 35 come first and 44 in the
top 3, with 2.40 tools returned; 25 as one sentence (35 in the top 3
against 33), learning from the train half loses nothing (35 and 44), and 41
come first asked again. What it misses is mostly neighbours of one object
(closing a box finds the list of boxes, asking for a new one finds the
tool that creates a customer) and
words the tools do not use ("ເຄື່ອງ", goods, or "ກ່ອງ", a box, where the
tools write "ພັດສະດຸ", a parcel).

The fleet of field sensors is exposed as many real servers are: its 40
tools were generated from an OpenAPI description. Each name is the method
and the path (`get_devices_device_id`, `post_devices_device_id_reboot`), no
tool has a title, each description is the summary of the operation, its
longer text and the method and path, and every list takes the same `page`
and `per_page`. Most tools are neighbours on one object (list, get, create,
update and delete a device; reboot, calibrate, read it, update its
firmware). Its 100 requests were written before any of them was run and
split as the airline's, 46 in train and 54 in test. Nothing was tuned on
it: 39 of its 54 test requests find the right tool first in fields, 48 in
the top 3, 29 as one sentence; asked again, 46. Taking the first line of a
multi-line description as the title of a tool that has none, tried on its
train half, found one more request in fields and lost two to three as one
sentence: it was not kept. Learning from its train half, with the plain
mean of the pairs, lost 3 first places, all neighbours ("change a site
address" found the creation of a site, learned from "add a site address");
scaled by the share of pairs that reached the tool (above), it loses none.

The ferry line is written in Turkish, whose words take one suffix after
another ("biletini", "seferlerin") and which people often type without its
letters ("sikayet" for "şikâyet"). Its titles start with a capital `İ` where
a request writes `i` ("İzin kaydet", "İskeleler"), the case that each letter
lowered on its own (above) settles. Most of its 40 tools are neighbours on
one object (sell, cancel, refund and move a ticket; add, cancel and delay a
sailing; block and unblock a card). Its 100 requests were written before any
of them was run and split as the servers before the airline's: the first
and third request of a tool in train, the second in test. Nothing was tuned
on it: 34 of its 50 test requests find the right tool first in fields, 42 in
the top 3, 31 as one sentence, with 2.14 tools returned; learned from its
train half, 34 and 42 again; asked again, 37. When it was first measured,
before learning was scaled by the share of pairs (above), learning lost it
one first place. Its train half scores 43 in fields, so its test half is the
harder one. What it misses is neighbours ("sil", delete, a ticket finds its
sale; "kaldır", call off, a sailing because the ship broke down finds the
report of a breakdown) and words the tools do not use ("nöbet", a
watch, for "vardiya", a shift; "anons" for "duyuru").

The kindergarten is written in Vietnamese, which writes each syllable apart,
so a word is often two terms ("học phí", fees), and which people very often
type without its tone marks and without "đ" ("hoc phi"), on a phone above
all. Its tools are neighbours by design (record and cancel a notice of
absence, add and remove a person allowed to pick a child up, issue and void
a receipt, plan and cancel an outing). Its 100 requests were written on
2026-11-22, before any of them was run, and split as the ferry line's; the
split is uneven, since the first request of a tool, which goes to train,
was mostly written with its marks and the second, which goes to test, often
without. Nothing was tuned on it: 18 of its 50 test requests find the right
tool first in fields, 31 in the top 3, 11 as one sentence; learned from its
train half, 18 again; asked again, 23. Its train half scores 42 in fields.
It was kept out on 2026-11-22 and 2026-11-24 because learning lost it a
first place, a neighbour sharing the object's pair; since a learned lift is
scaled by the share of the learned search the request holds (above), it
loses none. It missed most of the requests typed without marks: a syllable
without its mark was another word to the index, and its trigrams, all of
them short, rarely met the tool's. Since such a word counts as the tool word
it spells (above), 27 come first in fields, 42 in the top 3, 19 as one
sentence, 27 learned and 31 asked again. What it misses now is short
syllables that several tool words spell ("co" for "cô", a teacher, and
"có", present) and neighbours of one object.

Learning was measured both ways on purpose. Learned from other requests, it
gains a little and loses nothing; on requests people repeat, it gains more.
Learning by pairs of words, measured before the town hall came in, loses
one repeated request against learning word by word (304 against 305 first, 327 against 326 in the top 3) and wins 4 new
ones (288 against 284): new requests are the ones that count.
Stronger settings gained more on repeats and lost on new requests: they were
not kept.

Of the 25 requests of the first three servers that miss the first place in
fields, about half are near ties between two tools of the same object ("discussion of a pull request"
finds the comment tool before the pull request itself), and half use words
the tools do not ("case" for a ticket, "workshop" for an event). The second
kind is what `vocabulary` and the host's synonyms are for.

## Changing the ranking

- Run `composer benchmark`. A change must not lower the scores:
  `tests/Search/BenchmarkTest.php` holds the floors.
- Add requests to `tests/Fixtures/queries.json` **before** running them on your
  change, or the benchmark only measures what you tuned. Choose on `train`,
  judge on `test`.
- Split a new server's requests so each half holds the first request written
  for half of its tools: the first request of the tools of even rank goes to
  `train`, that of the tools of odd rank to `test`, and so on, alternating.
  The first request one writes for a tool is the plain one, its second a
  paraphrase. The servers so far put the first in `train` and the second in
  `test`, and their train half is easier for it: 862 of its 998 requests
  come first in fields against 762 of 999 on test, and the gap holds on
  every server added later (46 against 39 on the bike rental, 48 against 35
  on the newsroom). Split this way, the airline operations centre scores
  111 on train against 117 on test. The later requests of the train half, the third written
  for a tool, are as hard as the test half: 136 of 190 come first.
- So learning cannot be tuned on the train half of these servers. Learning
  helps where the text misses, and the train half misses half as often:
  learned from the rest of train, ten folds of it lose 8 first places
  (862 to 854) while the test half gains 5 (762 to 767), and a lift scaled
  by the share of the request's pairs ever learned won 5 on the folds and
  lost 4 on test. A setting of learning waits for servers split as above.
  The share of the pairs that reached each tool, which is not that one, was
  chosen on two folds of the whole train half, all servers together: the
  plain mean lost 8 first places there (1138 to 1130), the share 3 (to
  1135), and on test it turned a loss of 2 into a gain of 1 (1035 and 1038
  against 1037), the requests asked again unchanged (1134). Squaring the
  share (1135) and keeping only the pairs of the action field (1133; 1136
  with the share) were measured on the same folds and not kept: no better,
  or one request for a rule that ties learning to one field.
- Give the numbers before and after in the pull request, and update this page
  and the README: `BenchmarkTest` fails until their tables, and the sentence
  that sums the fields up, give the scores the benchmark measures.
