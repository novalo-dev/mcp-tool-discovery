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

On the benchmark below, the same requests find the right tool first 402 times
out of 499 in fields, and 230 times as one sentence.

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

Each word also gives its trigrams, padded: "status" gives ` st`, `sta`, `tat`,
`atu`, `tus`, `us `. Trigram matches count at 0.6 of word matches. They let
"statuses" meet "status" and "categories" meet "category" with no rule of any
language.

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
stale and nothing needs clearing. `FileIndexCache` writes PHP files that return
an array: OPcache keeps them compiled in shared memory. Its directory must be
one only the server writes to.

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
   use, and only those.
2. The client runs the tool it chose with that `search_id`.
3. If the run succeeds, each of those words, and each pair of them, counts
   one more run for that tool.

Next time, a tool is lifted by the share of the request's pairs of words that
led to it (its single word, for a request of one word), each pair counting
fully after `learningCap` (5) runs, by at most `learningWeight` (0.75) of the
best score.

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

The limits are the point:

- **Only words the tools use are kept**, checked again when the run comes back,
  so a store never holds a name, an address or anything a person typed beyond
  the server's own vocabulary.
- **It reorders, it never adds**: a tool the request does not describe is never
  brought in by what was learned.
- **Only visible tools**: a run of a tool the caller may not see teaches
  nothing, and a `search_id` made up by the client can at most lift a visible
  tool for words it already holds, within the cap.
- **One store per server**: two servers with different tools need two stores.

`MemoryLearningStore` lasts the process; `FileLearningStore` keeps a JSON file,
locked while it is written.

---

## The measures

Nine fictional servers: a workspace (50 tools, `acme_set_ticket_status`), a
developer platform (40 tools, `archiveRepository`), people operations (40
tools, `people.leave.approve`), a network of public libraries written in
French (40 tools, `prolonger-pret`), the office of a school written in German
(40 tools, `KlassenfahrtAbsagen`), a cloud hosting panel (176 tools,
`nimbo_detach_volume`), the service desk of a town hall written in Greek
(40 tools, `parking_permit_renew`) a newsroom with no titles and long
descriptions (40 tools, `schedule_article`) and the office of a residential
estate written in Chinese (40 tools, `repair_assign`). 997 requests, each written as one
sentence and in fields, with the tool a person would expect.
The settings were chosen on the `train` half; the `test` half was kept aside
and gives the score.

| Test half, 499 requests | Right tool first | Top 3 |
|---|---|---|
| The request in fields | 402 | 459 |
| The same request as one sentence | 230 | 318 |
| In fields, after every train request was run once | 403 | 459 |
| In fields, after the test requests were run once, then asked again | 430 | 471 |

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
written without. A word typed without its accent is another word to the
index, and only its trigrams away from the accent still meet the tool
(`εκδηλωση` asked, `εκδήλωση` in the tool).

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
- Give the numbers before and after in the pull request, and update this page
  and the README: `BenchmarkTest` fails until their tables, and the sentence
  that sums the fields up, give the scores the benchmark measures.
