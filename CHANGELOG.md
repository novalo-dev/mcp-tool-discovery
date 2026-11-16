# Changelog

All notable changes to this library are documented in this file. The format is
based on Keep a Changelog, and the library follows Semantic Versioning.

What is merged on `develop` but not released waits under **Unreleased**, and
becomes the next version's section when it is published.

## Unreleased

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

### Changed

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
