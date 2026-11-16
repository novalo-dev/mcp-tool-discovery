# Hosts

The library has no transport, no session and no JSON-RPC envelope: the host
keeps all three. What it asks of a host is small: its tool definitions, a gate,
the negotiated revision, and the two methods `tools/list` and `tools/call`
handed over. This page shows that wiring in a server written by hand.

---

## A server written by hand

[examples/stdio-server.php](examples/stdio-server.php) is a complete MCP
server over stdio, with no SDK: four note tools, a gate that hides deletion
from anyone but an admin, and the compact mode with the batch runner. The
tests run it in its own process, as a client would, so it stays true to the
library ([tests/Examples/StdioServerTest.php](../tests/Examples/StdioServerTest.php)).

```
php docs/examples/stdio-server.php
MCP_NOTES_ROLE=admin php docs/examples/stdio-server.php
```

Its parts, in the order a host writes them:

| Part | In the example | What it holds |
|---|---|---|
| Tool definitions | `definitions()` | the MCP Tool arrays the server already had; `ToolSet::of()` takes them unchanged |
| The gate | `NotesGate` | `visible()` for who may know a tool exists, `authorize()` for the call with its arguments, `call()` to run it |
| The revision | `initialize` in `answer()` | the client's revision when the library knows it, otherwise the latest handshake revision |
| The two methods | `tools/list`, `tools/call` in `answer()` | `listTools()` and `callTool()` return the `result` member; the host wraps it |

Everything else in the file is the host's: reading lines, answering `ping`,
ignoring notifications, the JSON-RPC errors for a method it does not serve or
a call with no name.

---

## What a host must keep

- **One `Discovery` per caller.** The gate answers for one caller. Over stdio
  the caller is the process for its whole life; over HTTP, build the gate and
  the `Discovery` for each request.
- **The revision the session agreed.** Pass it to every `listTools()` and
  `callTool()`: answers are cut to its fields, and a client validating strictly
  rejects a field its revision does not define.
- **Encoding as it is.** The answers already hold `stdClass` where JSON needs
  an empty object, so `json_encode()` with no option to force objects or
  arrays is enough.
- **Errors in the right place.** A refused or failed tool comes back inside
  the result with `isError`, as the specification asks; JSON-RPC errors are
  for requests the host cannot read.

The stateless revision `2026-07-28` has no `initialize` handshake, so the
example does not offer it. How a request gives its version there belongs to
the transport; once the host has read it, it goes to `Revision::fromString()`
like any other.
