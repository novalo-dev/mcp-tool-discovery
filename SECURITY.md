# Security policy

This library stands between an MCP client and a server's tools. A flaw in it can
let a client see or run a tool its server meant to hide, which is why reports
are welcome and handled first.

## Reporting a vulnerability

Report it privately, never in an issue or a pull request:
[Report a vulnerability](https://github.com/novalo-dev/mcp-tool-discovery/security/advisories/new),
under the **Security** tab of the repository.

Say what a client can obtain, on which version, and how to reproduce it. A
failing test is the best proof.

## What to expect

- an acknowledgement within 3 working days;
- a first assessment within 10 working days;
- a fix in a new version, then a published advisory that credits you, unless
  you prefer otherwise.

The fix is prepared in the private fork of the advisory, never on `develop`:
`develop` is public, and a commit there would show the flaw before a fixed
version exists. The order is the fixed release first, then the advisory, with
a CVE when it applies, so `composer audit` warns every server still on the
old version.

## In scope

- a mode that lists, finds, describes or runs a tool the host's gate hides;
- a call that reaches `ToolGate::call()` without `ToolGate::authorize()`;
- a change presented to the client as a read;
- an answer that reveals whether a hidden tool exists;
- input that makes the library crash, hang or use memory out of proportion;
- a search, its `search_id`, its `unmatched` words or its `vocabulary` that
  reveals anything of a hidden tool;
- a learning store that keeps a word no visible tool uses, or a learned lift
  that brings a tool the request does not describe or goes past its cap.

## Out of scope

- the host's own access rules, authentication and transport: the library
  receives their decisions, it does not make them;
- clients that ignore `destructiveHint` or the specification;
- the tools themselves;
- a `FileIndexCache` directory, or a `FileLearningStore` file, that someone
  other than the server can write to: the cache files are PHP the server
  includes, so that directory must be the server's alone;
- the order a learning store shared by several callers gives: a caller who
  runs a tool many times lifts it for the words they used, for everyone using
  that store. It never makes a hidden tool visible. A host that does not want
  callers to influence each other gives each one its own store, or none.

## Supported versions

The latest release receives security fixes. Until 1.0.0, the latest 0.x
release only.
