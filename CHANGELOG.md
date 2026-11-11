# Changelog

All notable changes to this library are documented in this file. The format is
based on Keep a Changelog, and the library follows Semantic Versioning.

What is merged on `develop` but not released waits under **Unreleased**, and
becomes the next version's section when it is published.

## Unreleased

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
