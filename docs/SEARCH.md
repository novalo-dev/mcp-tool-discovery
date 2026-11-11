# Search

`ToolSearch` finds the tools that answer a request, the best first. Every mode
reaches it through `ToolRunner::search()`, with the `SearchSetup` the host gave
`Discovery`; it can also be used on its own:

```php
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

On the benchmark below, the same requests find the right tool first 125 times
out of 150 in fields, and 57 times as one sentence.

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
| 50 | 2.3 ms | 0.6 ms | 0.5 ms |
| 150 | 7.7 ms | 1.9 ms | 1.6 ms |
| 500 | 22 ms | 6.1 ms | 5.2 ms |

A search with the three fields, on one machine; the times include the request.

## Learning

Off by default. With a `LearningStore` in the `SearchSetup`, the search learns
which tool answered which words. It is wired in the core, in `ToolRunner`, so
every mode learns the same way and none can skip the checks:

1. A search returns a `search_id`: the words of the request that the tools
   use, and only those.
2. The client runs the tool it chose with that `search_id`.
3. If the run succeeds, each of those words counts one more run for that tool.

Next time, a tool is lifted by the share of the request's words that led to
it, each word counting fully after `learningCap` (5) runs, by at most
`learningWeight` (0.75) of the best score.

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

Three fictional servers, each named in its own style: a workspace (50 tools,
`acme_set_ticket_status`), a developer platform (40 tools, `archiveRepository`)
and people operations (40 tools, `people.leave.approve`). 300 requests, each
written as one sentence and in fields, with the tool a person would expect.
The settings were chosen on the `train` half; the `test` half was kept aside
and gives the score.

| Test half, 150 requests | Right tool first | Top 3 |
|---|---|---|
| The request in fields | 125 | 140 |
| The same request as one sentence | 57 | 79 |
| In fields, after every train request was run once | 127 | 140 |
| In fields, after the test requests were run once, then asked again | 133 | 142 |

Learning was measured both ways on purpose. Learned from other requests, it
gains a little and loses nothing; on requests people repeat, it gains more.
Stronger settings gained more on repeats and lost on new requests: they were
not kept.

Of the 25 requests that miss the first place in fields, about half are near
ties between two tools of the same object ("discussion of a pull request"
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
  and the README.
