# Changelog

All notable changes to this library are documented in this file. The format is
based on Keep a Changelog, and the library follows Semantic Versioning.

What is merged on `develop` but not released waits under **Unreleased**, and
becomes the next version's section when it is published.

## Unreleased

### Added

- **An eighth benchmark server, written as many real servers are.** A
  newsroom: 40 tools in snake_case with no title, so the name stands in for
  one, and descriptions of 18 to 70 words that open with "Use this tool to"
  and point at their neighbours. Every other fixture has short descriptions
  and a title on every tool. Its 100 requests were written before any of
  them was run, and nothing was tuned on it: 35 of its 50 test requests find
  the right tool first in fields, 46 in the top 3, 30 as one sentence.
  Learning from its train half costs it one request, as on the school. The
  floors of `tests/Search/BenchmarkTest.php` move to the new counts on the
  449 test requests (362 first, 414 in the top 3, 388 asked again), so they
  still guard every request that passes today.
- **A server in a Symfony application**, `docs/examples/symfony`: the HTTP
  example on Symfony 6.4, the long-term release that runs on PHP 8.1. It shows
  which of the library's parts are services of the container (`SearchSetup`
  with its index cache, shared by every caller) and which must not be (the
  gate and the `Discovery`, built by the controller in each request, since a
  worker such as FrankenPHP keeps the container from one request to the
  next). It answers from the same `docs/examples/http.php`, and the tests ask
  it everything they ask the PHP-FPM server, plus one kernel serving callers
  in turn. A CI job installs the pinned `symfony/framework-bundle` and runs
  them; it is part of **CI passed**.
- **A server that stays up, on ReactPHP**, `docs/examples/react/server.php`:
  the HTTP example in one long-running process, as a ReactPHP, RoadRunner,
  Swoole or FrankenPHP worker runs it. It shows what such a host keeps between
  requests (one `MemoryIndexCache` in one shared `SearchSetup`) and what it
  must not (the gate and the `Discovery`, built inside each request). Both
  HTTP servers now answer from the same `docs/examples/http.php`, and the tests
  ask the ReactPHP server everything they ask the PHP-FPM one, plus callers
  taking turns in one process. A CI job installs the pinned `react/http` and
  runs them; it is part of **CI passed**.

- **Direct mode**, `src/Mode/Direct/DirectMode.php`: every tool the caller
  may see, listed as itself, and no tool of the mode's own. For a server
  small enough to list everything, and for a host that lets its users turn
  the other modes off: `$settings->compact ? new CompactMode() : new
  DirectMode()` keeps one path for `tools/list` and `tools/call`, where such
  a host used to need a second one that had to filter with the gate and shape
  each revision again on its own. Held to the mode contract and added to the
  conformance checks.
- **What each mode costs**, measured: `composer benchmark` now also prints
  the bytes of JSON of each mode's list and of one compact search answer on
  the six benchmark servers, and docs/MODES.md opens with "Choosing a mode",
  that table and when to take each mode. On the server with 176 tools the
  whole list is 60,640 bytes, the compact list 2,871 and the gateway list
  1,845, whatever the number of tools. `tests/Search/ListCostTest.php`
  guards those claims.
- **`Revision::negotiate()`**, the revision to answer `initialize` with: the
  client's own when the library shapes it, otherwise the latest revision with
  a handshake, as the specification asks. It takes `protocolVersion` as the
  client sent it, missing or not a string included, and never answers with
  the stateless revision, which has no handshake. **`Revision::latestWithHandshake()`**
  gives that fallback on its own. A host no longer names a revision of its
  own to fall back to, so it moves on when the library learns a newer one;
  the examples in docs/HOSTS.md use both.
- **The docs are tested.** `tests/Docs/DocsTest.php` reads every PHP snippet
  of the README and docs/ without running it: each must parse, and each class,
  method, enum case and named argument of the library it names must exist,
  with the case Composer's autoloader needs. Every relative link must lead to
  a file and its anchor to a heading. A snippet a host copies can no longer
  name a parameter the library renamed. Each class of the library a page
  names comes with its `use` line, in that block or one above it, and a name
  one typo or one case away from a class of the library (`new DirectMod()`,
  `new toolSet()`) fails instead of passing for a class of the host. The
  snippets of the grouped and gateway modes in the README, and those of
  docs/MODES.md and docs/SEARCH.md, now show their `use` lines: copied as
  they were, they stopped at "Class not found".

### Changed

- **Arguments sent as a JSON array never reach the gate.** `json_decode()`
  turns a JSON array into a PHP array as it does an object, so a host that
  checks `is_array()` and a mode that read `arguments` the same way handed a
  gate `[0 => ...]` where it expects named arguments. Only the batch runner
  refused that. `Discovery::call()` and `ToolRunner::run()` now answer
  "arguments must be an object." with `isError` before the mode or the gate
  sees anything, and the compact, grouped and gateway runners give the same
  answer for the `arguments` they carry. Both methods document what they
  really accept, `array<mixed>`, so static analysis no longer takes the check
  for dead code. `ToolRunner::isObject()` is the one rule they share, for
  modes written outside the library too; the empty array still stands for
  `{}`. The mode contract tests check it for every mode.
- **Grouped mode, a lighter list.** Each action used to be named up to four
  times: in the read tool's description, in its `action` or `describe` enum,
  and again in the change tool. It is now named twice, a line with its title
  in the tool that runs it and that tool's `action` enum. The read tool no
  longer repeats the changes, it names the change tool instead, and
  `describe` takes plain strings (an unknown name still comes back in
  `unknown`). A tool without a title is listed by its name alone rather than
  "name: name". On the workspace benchmark server the grouped list goes from
  15,444 to 14,314 bytes, and its eight group tools from 8,616 to 7,486.
  Clients that relied on the `describe` enum to check names now get the
  same answer from the call.

### Security

- `CallResult::json()` replaces each sequence that is not valid UTF-8 with
  U+FFFD, in the text and in the structured content alike, so an answer that
  carries a host tool's output, a `run_write_batch` report included, can
  always be encoded and reaches the client.
- `MemoryLearningStore` and `FileLearningStore` keep at most 20,000 counts
  of a word or pair for a tool, the most used ones, so what a store holds and
  what each search reads stay the same size however long it serves.

## 0.3.0 - 2026-11-16

### Added

- **Gateway mode**, `src/Mode/Gateway/GatewayMode.php`: a single tool,
  `use_tools`, for clients that accept very few tools. Each call gives an
  `operation`: `search` (the compact search, same fields and same answer),
  `describe` (`names`) or `run` (`name`, `arguments`, `search_id`, passed to
  the runner so the search learns). The tool is marked destructive as soon as
  the caller may run a change through it, and as a read when every visible
  tool only reads. With `changesApart: true` the changes are listed as
  themselves with their own annotations, the gateway only reads, and a change
  sent through `run` is refused before the gate is asked. Options: `prefix`,
  `title`, `changesApart`, `groupOf`, `maxDescribed`, `schemaOnError`. It
  passes the mode contract in both settings and is in the conformance checks.
- **Grouped mode**, `src/Mode/Grouped/GroupedMode.php`. The host declares its
  groups (`groups`: name => title) and says where each tool belongs
  (`groupOf`); each group holding a visible tool becomes a tool named after
  it, which runs the group's reads and describes any of its actions with
  `describe`, and `<group>_change`, destructive, which runs its changes. Every
  action of a group is listed in its tool's description and in an `enum`, so
  the client sees the map of the server without searching. An action sent to
  the wrong tool is refused before the gate is asked; a hidden one is answered
  like one that does not exist. A tool outside the declared groups is listed
  as itself. Options: `prefix`, `changeTitles`, `maxDescribed`,
  `schemaOnError`. It passes the mode contract and is in the conformance
  checks.
- **`ModeContractTestCase::searches()`**: a mode that does not search
  answers false, and the contract then checks it never teaches the search.

- **`run_write_batch` in compact mode**, off by default. `batchLimit: N` on
  `CompactMode` adds a fourth tool that runs one change tool once for each
  item of a list (`name`, `items`, `search_id`), up to N items a call, so a
  client changing thirty items asks the person once instead of thirty times.
  Each item goes through the gate on its own, as a call of `run_write_tool`
  would; the list is refused whole, before anything runs, when it is
  malformed, longer than the limit, or names a read, a runner or a tool the
  caller may not see. The answer reports `succeeded`, `failed` and each item's
  result, and is an error only when no item succeeded. The tool carries
  `destructiveHint`, tells the client to show the complete list before
  confirming, and the search learns once per batch. With the option left at
  0, the list of tools is unchanged and a host tool of that name stays the
  host's.
- **A server on the official PHP SDK, in `docs/examples/sdk/`.** The notes
  server on `mcp/sdk` 0.10.0 over stdio: one request handler answers
  `tools/list` and `tools/call` from the library, and the SDK keeps the
  handshake, sessions and the stateless revision. `docs/HOSTS.md` lists the
  five things a host on the SDK writes, among them decoding the arguments
  again as arrays, since the SDK hands nested objects over as `stdClass`. A
  CI job of its own installs the SDK at that exact version and runs the new
  `sdk` test suite; it stays out of **CI passed** while the SDK is below 1.0,
  and `mcp/sdk` never enters the library's `composer.json`.
- **A server over HTTP, in `docs/examples/http-server.php`.** The notes
  server served over the Streamable HTTP transport, one process per request as
  under PHP-FPM: the gate built from the request's `Authorization` header, the
  revision read from `MCP-Protocol-Version` after `initialize` (`2025-03-26`
  without it, 400 for one the library does not shape), the search index kept
  in a `FileIndexCache` between requests, and the 202, 401, 403 and 405 a
  host answers itself. `docs/HOSTS.md` says what changes from stdio. The tests
  run it behind PHP's built-in server and call it over HTTP. It also serves
  the stateless revision `2026-07-28`: `server/discover`, the revision read
  from each request's `_meta` and checked against the header, and error
  -32022 with the supported revisions for one it does not speak. The tools, the
  gate and the choice of revision moved to `docs/examples/notes.php`, shared
  by both examples.
- **A complete server written by hand, in `docs/examples/`.** A stdio MCP
  server with no SDK, in one file: four note tools, a gate that hides one of
  them from all but an admin, the compact mode with the batch runner, the
  revision negotiated at `initialize`, and the JSON-RPC errors a host keeps.
  `docs/HOSTS.md` walks through it. The new `examples` test suite runs it in
  its own process as a client would, and the coding standard, PHPStan at
  level max and the PHP 8.1 check read it, so the example stays true to the
  library.
- **A fourth benchmark server, in French.** `composer benchmark` now also
  measures a network of public libraries: 40 tools with kebab-case names and
  French titles and descriptions, and 100 requests written before any of them
  was run. Nothing in the search changed for it: 39 of its 50 test requests
  find the right tool first in fields. The test half is now 200 requests, and
  the floors in `BenchmarkTest` are the new counts (164 first, 183 in the
  top 3, 174 asked again); the share shown by the badge moves from 83% to 82%
  because the new server is harder, not because the search got worse.
- **A fifth benchmark server, in German.** The office of a school: 40 tools
  with PascalCase names (`KlassenfahrtAbsagen`) and German titles and
  descriptions, and 100 requests written before any of them was run. Nothing
  in the search changed for it: 38 of its 50 test requests find the right
  tool first in fields, 44 in the top 3. Learning from its train half moves
  one of them out of first place, while the benchmark as a whole still gains
  one: the first server where learned words met new requests and cost one.
  The test half is now 250 requests, and the floors in
  `BenchmarkTest` are the new counts (202 first, 227 in the top 3, 215 asked
  again); the badge moves from 82% to 81% for the same reason as above.
- **A sixth benchmark server, with 176 tools.** A cloud hosting panel in
  English with snake_case names (`nimbo_detach_volume`), most tools sharing
  an object with several neighbours (stop, power off, reboot a server;
  snapshot, back up, restore), and 197 requests written before any of them
  was run. The weights and the cut were not touched: 84 of its 99 test
  requests find the right tool first in fields, 94 in the top 3. The test
  half is now 349 requests, and the floors in `BenchmarkTest` are the new
  counts (286 first, 321 in the top 3, 304 asked again); the badge moves from
  81% to 82%.
- **A seventh benchmark server, in Greek.** The service desk of a town hall:
  40 tools with English snake_case names (`parking_permit_renew`) and Greek
  titles and descriptions, and 100 requests written before any of them was
  run, about half typed without accents as many people type Greek. Nothing
  in the search changed for it: 41 of its 50 test requests find the right
  tool first in fields, 47 in the top 3; learning from its train half moves
  none of them, and asked a second time 44 come first. Written with accents, 24 of 26 come first; without, 17 of 24. The
  test half is now 399 requests, and the floors in `BenchmarkTest` are the
  new counts (327 first, 368 in the top 3, 348 asked again); the badge stays
  at 82%.

### Changed

- **Learning works by pairs of words.** A run that succeeds now records the
  pairs of words of its search beside the words, and a request of two words
  or more is lifted by the pairs it shares with past searches. Word by word,
  one run of "get the certificate" lifted the tool that shows a certificate
  for every request naming a certificate, "delete" ones included: on the
  176-tool server, learning from the train half cost 3 test requests their
  first place, and the benchmark broke its own rule that learning never
  hurts. By pairs it costs none, and learning from other requests now gains
  2 (288 first against 286 with nothing learned). Repeated requests lose one
  first place against word by word (304 against 305) and gain one in the top
  3. A request of one word still uses the single words. Nothing changes in
  `LearningStore`: a pair is one more key, two words with a space between.
  A store filled before keeps lifting requests of one word and learns the
  pairs from the next runs, so a request of several words starts again from
  what the text finds. The pairs come from at most 16 words of a search.
- The compact search moved to `src/Mode/ToolFinder.php`, shared with the
  gateway mode, so both answer a request with the same tools. Its answers
  are unchanged.
- **A cached search is about five times faster.** `ToolIndex` is now an
  inverted index: each word and trigram points to the tools that hold it,
  with its weight there worked out when the index is built, so a search only
  visits the tools that share a term with the request instead of every tool
  for every term. With a cache, a search over 500 tools goes from about 6 ms
  to about 1 ms, over 50 tools from 0.6 ms to 0.1 ms; building the index costs
  the same. The scores are identical to the last digit, so the ranking and the
  benchmark do not move. An index saved by an `IndexCache` in the former shape
  is rebuilt once, on its own; the new one is about a third larger on disk.
- **Building the index is about a third faster**, which is the cost of every
  search made without a cache: over 500 tools it goes from about 25 ms to
  about 16 ms, over 50 tools from 2.3 ms to 1.5 ms. Each word is cut into
  trigrams once per build instead of once per field it appears in, and a word
  written in ASCII is cut by bytes rather than split into characters. The
  index is the same to the last digit, saved shape included, so a cache entry
  stays valid and the ranking does not move.

### Fixed

- A tool whose name is only digits, such as `"123"`, no longer breaks the
  search: every `search_tools` call (and `use_tools` with `search`) of a
  server holding one failed with a `TypeError`, in every mode. PHP turns such
  a name into an integer array key; `ToolSet` now hands out the tool's own
  name everywhere, so `names()` and a `foreach` over the set always give
  strings. `ToolSet::getIterator()` returns a `Generator` instead of an
  `ArrayIterator`, which a host only notices if it called `ArrayIterator`
  methods on it.

### Security

- A search reads at most the first 1024 bytes of each field of a request
  (`action`, `object`, `description`, `query`), cut between two characters,
  so its cost stays small whatever the size of the request.
- The notes example shared by the stdio and HTTP servers cuts the start of
  each note it lists between two characters, so its list answers for notes
  written in any alphabet.

## 0.2.0 - 2026-11-14

### Changed

- **`Search\Index` is now `Search\ToolIndex`.** A host that drops an
  `index.php` in every folder of its package, as some plugin stores require,
  put it beside `Index.php`, and unpacked on a filesystem that ignores case one
  of the two replaced the other. Code that built or restored the index itself
  (`Index::build()`, `Index::fromArray()`) uses the new name; arrays saved by
  an `IndexCache` are read back unchanged.

### Added

- `bin/check-hygiene.php` refuses two paths that differ by case alone, and a
  file named `index.php` in any case.

## 0.1.0 - 2026-11-14

### Added

- **Compact mode.** A server shows its clients three tools instead of its whole
  list: `search_tools` to find a tool and read its parameters, then
  `run_read_tool` or `run_write_tool` to run it. The change runner carries
  `destructiveHint`, so clients ask the person before a change, and each runner
  refuses the other kind of tool. Options: a prefix for the three names, tools
  kept in the list as themselves, translated titles and groups for the full
  list.
- **Search settings for every mode.** `SearchSetup` holds the search options,
  the cache and the learning store, both optional, and is given once to
  `Discovery`. Modes search through `ToolRunner::search()`, and the runner
  learns from a run that succeeded, so a server that changes modes keeps what
  it learned and no mode can skip the checks.
- **Tool search in fields.** The client says the action, the object and the
  request in its own words in separate fields of `search_tools`; each field is
  scored on its own with BM25F over the name, title, description and
  parameters of each tool, with trigrams for plurals and near spellings. On 150
  requests kept aside over three servers named in three styles, the right tool
  comes first 125 times, against 57 for the same requests as one sentence. Free
  keywords still work.
- **No list and no naming convention.** The search holds no stop words, no
  verbs and no prefix rule, in any language: a word every tool carries weighs
  almost nothing by itself. Synonyms and stop words are the host's, when it
  gives them.
- **Words to say it again.** When words of a search match no tool, the answer
  lists them and gives the words of the visible tools' titles.
- **Index cache.** `MemoryIndexCache` for a long-running server,
  `FileIndexCache` for one where each request starts afresh, read through
  OPcache. One index per set of visible tools, so a caller never reads an index
  built over tools hidden from them.
- **Learning, opt in.** With a `LearningStore`, a search returns a `search_id`
  and a run that succeeds with it lifts that tool for those words next time.
  Only words the tools use are kept, never what a person typed beyond them;
  what was learned reorders the tools a query finds, within a cap, and never
  brings a tool the query does not describe.
- **The host keeps the control.** `ToolGate` is the one interface a server
  implements: what the caller may see, whether a call may go ahead, and the
  call itself. A mode only reaches tools through `ToolRunner`, which asks the
  gate first.
- **Answers shaped for each revision of MCP**, from `2024-11-05` to
  `2026-07-28`, validated in CI against each revision's official schema. Empty
  PHP arrays that must be JSON objects are sent as objects.
- **A contract for modes.** `DiscoveryMode` and the contract tests every mode
  must pass, for the Grouped and Gateway modes open to contribution.
