# Roadmap

The foundations are in place: the contract every mode follows, the gate that
keeps every access decision with the host, answers shaped and validated for
each revision of MCP, the compact mode, and a tool search that takes the
request in fields, caches its index and can learn. What is listed here is the
work that would make the library useful to many more servers.

Each item can be taken on its own. Comment on the matching issue, or open one,
before starting something large, so two people do not build the same thing.

## Modes

Two modes are wanted, each a pack of its own in `src/Mode/<Name>/`, held to
the same contract tests as the compact mode. [docs/MODES.md](docs/MODES.md)
describes what each should do.

- **Grouped**: one tool per group of tools ("files", "calendar"), each
  listing its actions, for clients that handle a few dozen tools well.
- **Gateway**: a single tool that searches, describes and runs, for clients
  that accept only one.

## Search

The benchmark says where the search still misses, in
[docs/SEARCH.md](docs/SEARCH.md#the-measures):

- **Near ties** between two tools of the same object, such as a pull request
  and the comments of a pull request.
- **Words the tools do not use**, such as "case" for a ticket. The answer
  already gives the server's own words; a measured way to close the gap
  without a list built into the library is welcome.
- **More fixture servers**, named in other styles and in other languages, with
  their requests written before any tuning.

A change to the search comes with the benchmark before and after.

## Hosts

- Examples of the gate for the official PHP SDK and for a handler written by
  hand, under `docs/`.
- Reports from servers that use the library: what their clients searched,
  what was missed. They decide what the search learns next.

## Tests

Every push runs the CI: the coding standard, PHP 8.1+ compatibility, PHPStan
at level max, the tests on PHP 8.1 to 8.5, the contract tests of every mode,
the conformance suite against the official schemas, the search benchmark,
Semgrep, Psalm's taint analysis and Gitleaks. A new revision of MCP is watched
every three days.
