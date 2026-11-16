# Changelog

All notable changes to this library are documented in this file. The format is
based on Keep a Changelog, and the library follows Semantic Versioning.

What is merged on `develop` but not released waits under **Unreleased**, and
becomes the next version's section when it is published.

## Unreleased

### Added

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
- **A fourth benchmark server, in French.** `composer benchmark` now also
  measures a network of public libraries: 40 tools with kebab-case names and
  French titles and descriptions, and 100 requests written before any of them
  was run. Nothing in the search changed for it: 39 of its 50 test requests
  find the right tool first in fields. The test half is now 200 requests, and
  the floors in `BenchmarkTest` are the new counts (164 first, 183 in the
  top 3, 174 asked again); the share shown by the badge moves from 83% to 82%
  because the new server is harder, not because the search got worse.

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
