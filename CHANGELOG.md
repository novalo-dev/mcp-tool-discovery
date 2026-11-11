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
  kept in the list as themselves, translated titles, groups for the full list,
  and the search settings.
- **Tool search.** BM25F over the name, title, description and parameters of
  each tool, with trigrams for plurals and near spellings, verb intent for
  queries such as "change" or "turn off", object coverage, synonyms given by the
  host, and a cut that keeps only the tools close to the best.
- **The host keeps the control.** `ToolGate` is the one interface a server
  implements: what the caller may see, whether a call may go ahead, and the
  call itself. A mode only reaches tools through `ToolRunner`, which asks the
  gate first.
- **Answers shaped for each revision of MCP**, from `2024-11-05` to
  `2026-07-28`, validated in CI against each revision's official schema. Empty
  PHP arrays that must be JSON objects are sent as objects.
- **A contract for modes.** `DiscoveryMode` and the contract tests every mode
  must pass, for the Grouped and Gateway modes open to contribution.
