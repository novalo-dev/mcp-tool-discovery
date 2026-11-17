# Hosts

The library has no transport, no session and no JSON-RPC envelope: the host
keeps all three. What it asks of a host is small: its tool definitions, a gate,
the negotiated revision, and the two methods `tools/list` and `tools/call`
handed over. This page shows that wiring in two servers written by hand, one
over stdio and one over HTTP, and in one on the official PHP SDK. All three
serve the same notes tools, kept with their gate in
[examples/notes.php](examples/notes.php): only the transport differs.

---

## A server over stdio

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
| Tool definitions | `definitions()`, in `notes.php` | the MCP Tool arrays the server already had; `ToolSet::of()` takes them unchanged |
| The gate | `NotesGate`, in `notes.php` | `visible()` for who may know a tool exists, `authorize()` for the call with its arguments, `call()` to run it |
| The revision | `initialize` in `answer()`, through `Revision::negotiate()` | the client's revision when the library shapes it, otherwise the latest revision with a handshake, as the specification asks |
| The two methods | `tools/list`, `tools/call` in `answer()` | `listTools()` and `callTool()` return the `result` member; the host wraps it |

Everything else in the file is the host's: reading lines, answering `ping`,
ignoring notifications, the JSON-RPC errors for a method it does not serve or
a call with no name.

---

## A server over HTTP

[examples/http-server.php](examples/http-server.php) serves the same tools
over the Streamable HTTP transport, the way most PHP applications run: one
process per request, behind PHP-FPM or any web server. It answers every
message with one JSON body and keeps no session. The tests run it behind PHP's
built-in server and call it over HTTP
([tests/Examples/HttpServerTest.php](../tests/Examples/HttpServerTest.php)).

```
php -S 127.0.0.1:8080 docs/examples/http-server.php
curl -s http://127.0.0.1:8080/mcp -H 'Authorization: Bearer reader-token' \
  -H 'MCP-Protocol-Version: 2025-11-25' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

What changes from stdio:

| Part | Over HTTP | Why |
|---|---|---|
| The caller | the `Authorization` header of each request | the gate is built for the caller this request names, so a `Discovery` lives for one request |
| The revision | `initialize` negotiates it; every later request sends it in `MCP-Protocol-Version`. In the stateless revision each request names it in `_meta` instead | there is no process to remember it. With no header the server assumes `2025-03-26`, as the specification says; a revision it does not speak gets 400 |
| The notes | a file in `MCP_NOTES_DATA` | each request is a new process; a real server uses its database |
| The search index | `FileIndexCache` in the same directory | built once, then read by every request instead of built again |

The rest is the transport's: 405 for a `GET` (no stream to open), 202 for a
notification, 401 without a known token, and 403 for a browser page of
another origin, which the specification asks a server to refuse. The cache
directory must be one only the server writes to: the index files are run.

---

## A server on the official PHP SDK

[examples/sdk/server.php](examples/sdk/server.php) serves the same tools on
[`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk), the official
PHP SDK, over its stdio transport. The SDK keeps all it already does: the
handshake, sessions, the stateless revision, JSON-RPC errors. The library takes
over `tools/list` and `tools/call` through one request handler, which the SDK
asks before its own. The SDK needs PHP 8.2; the example pins its version in
[examples/sdk/composer.json](examples/sdk/composer.json), and a CI job installs
it there and runs [tests/Sdk/SdkServerTest.php](../tests/Sdk/SdkServerTest.php),
so the example is proved against that version.

```
cd docs/examples/sdk && composer install && cd -
php docs/examples/sdk/server.php
```

In your own project, `composer require mcp/sdk novalo/mcp-tool-discovery`, then:

| Part | On the SDK | Why |
|---|---|---|
| The handler | `ToolsHandler`, added with `addRequestHandler()` | it answers `ListToolsRequest` and `CallToolRequest` from `listTools()` and `callTool()`; the SDK's registry of tools stays empty |
| The capability | `setCapabilities(new ServerCapabilities(tools: true, ...))` | with an empty registry the SDK would not announce tools by itself |
| The revision | `RequestContext::getProtocolVersion()`, read into `Revision::fromString()` | the SDK has already agreed on it, by handshake or by the request's `_meta` |
| The arguments | decoded again as arrays | the SDK keeps nested objects as `stdClass`; without this, every item of a batch is refused as not an object |
| The answer | `Answer`, a `ResultInterface` holding the array | the SDK encodes an object; in the stateless revision it keeps the `resultType`, `ttlMs` and `cacheScope` the answer carries |

Over the SDK's HTTP transport the handler stays the same; build the gate and
the `Discovery` from the caller of each request, as in the HTTP example.

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

The stateless revision `2026-07-28` has no `initialize` handshake and no
session at all, which suits one process per request. The HTTP example serves
it beside the others:

- **`server/discover`** replaces the handshake: it lists the revisions the
  server speaks and its capabilities, the same for every caller.
- **Every request names its revision** in
  `params._meta["io.modelcontextprotocol/protocolVersion"]`, and the
  `MCP-Protocol-Version` header must say the same; a mismatch gets 400.
- **A revision the server does not speak** gets 400 with error `-32022`,
  whose `data` gives the requested revision and the supported ones, so the
  client can choose and retry.

Once the host has read the revision, it goes to `Revision::fromString()` like
any other, and the library adds the `resultType`, `ttlMs` and `cacheScope`
that revision asks for. The stdio example keeps to the revisions with a
handshake.
