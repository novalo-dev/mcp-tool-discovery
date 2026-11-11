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

## In scope

- a mode that lists, finds, describes or runs a tool the host's gate hides;
- a call that reaches `ToolGate::call()` without `ToolGate::authorize()`;
- a change presented to the client as a read;
- an answer that reveals whether a hidden tool exists;
- input that makes the library crash, hang or use memory out of proportion.

## Out of scope

- the host's own access rules, authentication and transport: the library
  receives their decisions, it does not make them;
- clients that ignore `destructiveHint` or the specification;
- the tools themselves.

## Supported versions

The latest release receives security fixes. Until 1.0.0, the latest 0.x
release only.
