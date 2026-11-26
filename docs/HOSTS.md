# Hosts

The library has no transport, no session and no JSON-RPC envelope: the host
keeps all three. What it asks of a host is small: its tool definitions, a gate,
the negotiated revision, and the two methods `tools/list` and `tools/call`
handed over. This page shows that wiring in two servers written by hand, one
over stdio and one over HTTP, in the same HTTP server kept up in one process
on ReactPHP, in a Symfony application, in a Laravel application, in a server
written with laravel/mcp, in two on logiscape/mcp-sdk-php, and in four on
the official PHP SDK. All twelve serve
the same notes tools, kept with their gate in
[examples/notes.php](examples/notes.php) (the laravel/mcp server writes them
as its own tool classes): only the transport differs.
Two of the SDK servers keep their tools outside the SDK, over stdio and over
HTTP; the other two keep them in the SDK's own registry, added with
`addTool()` or found by their `#[McpTool]` attribute.

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
MCP_NOTES_ROLE_FILE=role.txt MCP_NOTES_MODE=direct php docs/examples/stdio-server.php
MCP_NOTES_SEARCH_LOG=search.log php docs/examples/stdio-server.php
MCP_NOTES_MODE=grouped php docs/examples/stdio-server.php
```

The third reads the role again from `role.txt` at each message and lists
every tool as itself: write `admin` in the file while a client is connected
and the server sends `notifications/tools/list_changed`, as
[When the tools change](#when-the-tools-change) describes. The fourth writes
each search and each run after it as a line of `search.log`, as
[the log](SEARCH.md#log) describes;
`php docs/examples/search-log-report.php search.log` then prints its misses.
The last shows the same tools in grouped mode: `MCP_NOTES_MODE` takes
`compact` (the default), `direct`, `grouped` or `gateway`, chosen by
`mode()` in `notes.php`, which the ReactPHP and SDK servers read too, so each
mode can be tried on a real client with the same tools and the same gate. The
tests hold the two that do not search to what [MODES.md](MODES.md) says: in
grouped mode a role granted adds the deletion to `notes_change` and the client
is told; in gateway mode the one tool stays as it was, already destructive
since a reader may add a note, and a search marks the deletion with
`"changes": true`.

Its parts, in the order a host writes them:

| Part | In the example | What it holds |
|---|---|---|
| Tool definitions | `definitions()`, in `notes.php` | the MCP Tool arrays the server already had; `ToolSet::of()` takes them unchanged |
| The gate | `NotesGate`, in `notes.php` | `visible()` for who may know a tool exists, `authorize()` for the call with its arguments, `call()` to run it; the last two come from `NotesTools`, which the Symfony and Laravel examples reuse behind their own gates |
| The revision | `initialize` in `answer()`, through `Revision::negotiate()` | the client's revision when the library shapes it, otherwise the latest revision with a handshake, as the specification asks |
| The two methods | `tools/list`, `tools/call` in `answer()` | `listTools()` and `callTool()` return the `result` member; the host wraps it |
| The mode | `mode()`, in `notes.php`, from `MCP_NOTES_MODE` | one line a host writes once; compact unless the variable names another |
| The search | one `SearchSetup`, with a `MemoryIndexCache` and a `MemoryLearningStore` | the index is built at the first search, not at every one; the process serves one caller, so what a successful run teaches lifts only that caller's tools, for its session |
| The log | `NotesSearchLog`, in `notes.php`, when `MCP_NOTES_SEARCH_LOG` names a file | one JSON line per search (the fields the client filled, the tools found, the words no tool uses) and per run (the tool, whether it succeeded, the `search_id` of its search); nothing is kept unless a file is named |
| A changed list | the loop, with the `listFingerprint()` of the list the client holds | `listChanged` declared at `initialize`, and the notification sent when the fingerprint of the next message differs |

Everything else in the file is the host's: reading lines, answering `ping`,
ignoring notifications, the JSON-RPC errors for a method it does not serve or
a call with no name.

---

## A server over HTTP

[examples/http-server.php](examples/http-server.php) serves the same tools
over the Streamable HTTP transport, the way most PHP applications run: one
process per request, behind PHP-FPM or any web server. It answers every
message with one JSON body and keeps no session. What it answers is in
[examples/http.php](examples/http.php), one function from a request to its
answer, which the ReactPHP and Symfony servers below call too. The tests run it behind
PHP's built-in server and call it over HTTP
([tests/Examples/HttpServerTestCase.php](../tests/Examples/HttpServerTestCase.php)).

```
php -S 127.0.0.1:8080 docs/examples/http-server.php
curl -s http://127.0.0.1:8080/mcp -H 'Authorization: Bearer reader-token' \
  -H 'MCP-Protocol-Version: 2025-11-25' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

What changes from stdio:

| Part | Over HTTP | Why |
|---|---|---|
| The caller | the `Authorization` header of each request, read by `roleOf()` | the gate is built for the caller this request names, so a `Discovery` lives for one request |
| The revision | `initialize` negotiates it; every later request sends it in `MCP-Protocol-Version`. In the stateless revision each request names it in `_meta` instead | there is no process to remember it. With no header the server assumes `2025-03-26`, as the specification says; a revision it does not speak gets 400 |
| The notes | a file in `MCP_NOTES_DATA` | each request is a new process; a real server uses its database |
| The search index | `FileIndexCache` in the same directory, made by `indexCache()` of `notes.php`, which hands its failures to `error_log()` | built once, then read by every request instead of built again; a directory the server cannot write never fails a request, so the reason goes to the log, or every search would build the index again unseen |

The rest is the transport's: 405 for a `GET` (no stream to open), 202 for a
notification, 401 without a known token, and 403 for a browser page of
another origin, which the specification asks a server to refuse. The cache
directory must be one only the server writes to: the index files are run.

`answerHttp()` never reads the token itself: it asks the server for the
`Discovery` of the caller, and answers 401 when there is none. Reading the
token is the server's part, in `roleOf()`, so a framework that already knows
who calls builds the gate from its own user instead, as the Symfony example
below does.

---

## A server that stays up, on ReactPHP

[examples/react/server.php](examples/react/server.php) is the same HTTP server
in one PHP process that serves every request, on
[ReactPHP](https://reactphp.org)'s event loop. The same holds for a worker of
RoadRunner, Swoole or FrankenPHP: the process outlives the request. It answers
from the same [examples/http.php](examples/http.php), so a client cannot tell
the two apart: the tests ask it everything they ask the PHP-FPM server
([tests/React/ReactServerTest.php](../tests/React/ReactServerTest.php)). The
example pins `react/http` in
[examples/react/composer.json](examples/react/composer.json), and a CI job
installs it there and runs those tests.

```
cd docs/examples/react && composer install && cd -
php docs/examples/react/server.php
```

What changes from one process per request:

| Part | In a process that stays up | Why |
|---|---|---|
| The caller | still a gate and a `Discovery` per request, built inside the handler | the process serves every caller in turn; a `Discovery` built once above the handler would answer each of them with the first caller's gate |
| The search index | one `MemoryIndexCache`, in one `SearchSetup` shared by every request | built once per set of visible tools and kept in memory; its key is the tools the caller may see, so a reader never gets the index of an admin's tools |
| The notes | one `Notes` in memory | the process keeps them; a real server keeps its database connection the same way |

A learning store, if the host gives one, is shared the same way: the
`SearchSetup` is built once, beside the cache.

A process that stays up can also tell a client its list has changed between
two of its requests, which a process per request cannot. The example does it
over the Streamable HTTP transport:

| Part | In the example | Why |
|---|---|---|
| The session | `initialize` answers with an `Mcp-Session-Id`, kept with the `Authorization` that opened it, and declares `listChanged` | a session belongs to its caller: another token, or an id the server never gave, gets 404 |
| The stream | a `GET` that names the session opens a `text/event-stream`, one per session | the channel the specification gives a server for messages no request asked for |
| The list the client holds | each `tools/list` of the session keeps its `listFingerprint()`, whichever page was asked | the fingerprint covers the whole list |
| The look | a timer of the event loop builds the Discovery of each listening session with the rights its caller has now, and sends `notifications/tools/list_changed` once when the fingerprint differs | a server that learns of a change by an event sends it then, with no timer |
| The end of a session | a `DELETE` naming it ends it (204); one nobody listens on goes after `MCP_SESSION_IDLE` seconds without a request (600); a caller holds 16 at most, and a new one lets its least recently used go | the process outlives every client, so what it keeps must be bounded; a client whose session is gone gets 404 and initializes again |

```
MCP_NOTES_MODE=direct MCP_NOTES_GRANTS=grants.json php docs/examples/react/server.php
```

Write `{"reader": "admin"}` in `grants.json` while a reader listens on its
stream, and the notification arrives; the tests do it, then check that it
came once and that the next `tools/list` shows the admin's tools. A request
with no session is answered as before, so a client that opens none sees the
same server as on PHP-FPM.

---

## A server in a Symfony application

[examples/symfony](examples/symfony) is the same HTTP server as a Symfony 6.4
application with the security bundle: a firewall finds the caller, a voter
decides which tools they see, the library's parts are services of the
container, the transport is one controller,
[McpController](examples/symfony/src/McpController.php), and the Discovery is
built for each request. It answers from the same
[examples/http.php](examples/http.php), and the tests ask it everything they
ask the PHP-FPM server, plus one kernel serving callers in turn, as a worker
does, with a request that names no one between two that do
([tests/Symfony/SymfonyServerTest.php](../tests/Symfony/SymfonyServerTest.php)).
The example pins `symfony/framework-bundle` and `symfony/security-bundle` in
[examples/symfony/composer.json](examples/symfony/composer.json), and a CI job
installs them there and runs those tests.

```
cd docs/examples/symfony && composer install && cd -
php -S 127.0.0.1:8080 docs/examples/symfony/public/app.php
```

The wiring is in [Kernel.php](examples/symfony/src/Kernel.php); a real
application writes the same entries in `config/packages/security.yaml` and
`config/services.yaml`:

| Part | In the application | Why |
|---|---|---|
| The caller | a stateless firewall on `/mcp` with `access_token`, whose [TokenHandler](examples/symfony/src/TokenHandler.php) names a user | the controller never reads the token; a request with no token reaches it with no user and is answered 401 |
| Who sees a tool | [ToolVoter](examples/symfony/src/ToolVoter.php), asked through `isGranted()` by [SecurityGate](examples/symfony/src/SecurityGate.php)'s `visible()` | the permission lives where the application keeps every other one, and the role hierarchy applies to tools too |
| What a call may carry and does | `NotesTools`, in `notes.php`, the same rules `NotesGate` uses | only the decision of who sees what is Symfony's; the tools keep their own checks |
| `SearchSetup`, with a `FileIndexCache` | a shared service, the cache in the directory of `MCP_NOTES_DATA` | the same for every caller: the cache is keyed by the tools a caller may see |
| The notes | a shared service | they stand for the host's database, which the container already gives its services |
| The gate and the `Discovery` | not services: the controller builds them in each request | a shared service would hand one caller's gate to the next request a worker serves (FrankenPHP, RoadRunner, Swoole), where the container outlives the request |

A token the firewall does not know is answered 401 by Symfony itself, before
the controller, so such a request gets 401 even where the other servers would
first answer 403 for its `Origin` or 405 for its method. It learns nothing
either way.

---

## A server in a Laravel application

[examples/laravel](examples/laravel) is the same HTTP server as a Laravel 13
application: a guard finds the caller, an ability of Laravel's gate decides
which tools they see, the library's parts are singletons of the container,
the transport is one controller,
[McpController](examples/laravel/app/McpController.php), and the Discovery is
built for each request. It answers from the same
[examples/http.php](examples/http.php), and the tests ask it everything they
ask the PHP-FPM server, plus one application serving callers in turn, as
Octane does, with a request that names no one between two that do
([tests/Laravel/LaravelServerTest.php](../tests/Laravel/LaravelServerTest.php)).
The example pins `laravel/framework` in
[examples/laravel/composer.json](examples/laravel/composer.json), and a CI job
installs it there and runs those tests. Laravel 13 needs PHP 8.3; the library
itself still runs on 8.1.

```
cd docs/examples/laravel && composer install && cd -
php -S 127.0.0.1:8080 docs/examples/laravel/public/app.php
```

The wiring is in [McpServiceProvider](examples/laravel/app/McpServiceProvider.php)
and [bootstrap/app.php](examples/laravel/bootstrap/app.php); a real
application puts the guard in its own `config/auth.php`, as
[the example's](examples/laravel/config/auth.php) does:

| Part | In the application | Why |
|---|---|---|
| The caller | an `mcp` guard on the `mcp-token` driver, added with `Auth::viaRequest()`, which reads `bearerToken()` and names a user | the controller never reads the token; a request with no token or an unknown one has no user, and is answered 401 |
| Who sees a tool | the ability `see-mcp-tool`, defined on Laravel's gate and asked through `allows()` by [AbilityGate](examples/laravel/app/AbilityGate.php)'s `visible()`, for `forUser()` of the caller | the permission lives where the application keeps every other one, and a `Gate::before()` of the application applies to tools too |
| The route | `Route::any('/mcp')`, outside the `web` group | the session, the cookies and the CSRF check of that group are for a browser; the controller answers 405 for the methods the transport does not take |
| What a call may carry and does | `NotesTools`, in `notes.php` | only the decision of who sees what is Laravel's; the tools keep their own checks |
| `SearchSetup`, with a `FileIndexCache` | a singleton, the cache in the directory of `config('mcp.data')` | the same for every caller: the cache is keyed by the tools a caller may see |
| The gate and the `Discovery` | not bound in the container: the controller builds them in each request | a singleton would hand one caller's gate to the next request Octane serves, where the container outlives the request |

A guard keeps the user it found. Octane forgets the guards after each
request; a worker that keeps one application without Octane must call
`forgetGuards()` on the auth manager after each request too, as the test
does, or the next request that names no one is served as the last caller.

---

## A server written with laravel/mcp

[examples/laravel-mcp](examples/laravel-mcp) is a server written as
laravel/mcp, Laravel's official MCP package, documents one: a `Server` class
with its list of `Tool` classes, routed by `Mcp::web()`. The library answers
two of its methods and nothing else; the tools, the transports,
`initialize` and every other method stay laravel/mcp's. The whole wiring is
the `boot()` of [NotesServer](examples/laravel-mcp/app/NotesServer.php):

```php
final class NotesServer extends Server
{
    protected array $tools = [ListNotes::class, GetNote::class, AddNote::class, DeleteNote::class];

    protected function boot(): void
    {
        $this->instructions = instructions(DiscoveryMethod::mode());
        $this->addMethod('tools/list', ListToolsMethod::class);
        $this->addMethod('tools/call', CallToolMethod::class);
    }
}
```

laravel/mcp reads `$instructions` at each message, after `boot()`, so the
server takes them from the same mode the methods list with
([Instructions](MODES.md#instructions)), and they cannot name a tool the
list does not have.
Both methods extend [DiscoveryMethod](examples/laravel-mcp/app/DiscoveryMethod.php),
which builds the Discovery of the request from what laravel/mcp's
`ServerContext::tools()` keeps for it, and
[ContextGate](examples/laravel-mcp/app/ContextGate.php) runs a tool the way
laravel/mcp's own `tools/call` does. The tests send every request through the
application's HTTP kernel, callers in turn
([tests/LaravelMcp/LaravelMcpServerTest.php](../tests/LaravelMcp/LaravelMcpServerTest.php)).
The example pins `laravel/framework` and `laravel/mcp` in
[examples/laravel-mcp/composer.json](examples/laravel-mcp/composer.json), and
a CI job installs them there and runs those tests.

```
cd docs/examples/laravel-mcp && composer install && cd -
php -S 127.0.0.1:8080 docs/examples/laravel-mcp/public/app.php
```

| Part | In the server | Why |
|---|---|---|
| Who sees a tool | the tool's own `shouldRegister()`, as in [DeleteNote](examples/laravel-mcp/app/Tools/DeleteNote.php) | it is how laravel/mcp hides a tool; the library is only given what `ServerContext::tools()` keeps, so a hidden tool can be neither found nor run through a runner |
| Running a tool | `ToolInvoker`, with `mcp.request` bound to the tool's own call for its time, as laravel/mcp's `ToolSearch` does | the tool's `Request`, its `validate()`, the container's injection and its error results behave as under laravel/mcp's `tools/call` |
| What a call may carry | the tool's `handle()`, so `authorize()` lets every call through | laravel/mcp tools validate their arguments themselves and answer an error result |
| The revision | `io.modelcontextprotocol/protocolVersion` of the request's `_meta` on 2026-07-28, else the `MCP-Protocol-Version` header, else 2025-03-26 over HTTP | laravel/mcp keeps no session; over stdio, where there is no header, the methods answer in the newest revision with a handshake |
| Progress notifications | dropped | a tool run through a runner would stream them under a request the client did not send |
| `SearchSetup`, with a `FileIndexCache` | a singleton, injected into each method | laravel/mcp builds the methods for each message, so the Discovery and its gate never outlive the request |

laravel/mcp also ships a `ToolSearch` of its own; the README compares the two
searches on the same benchmark.

---

## A server on logiscape/mcp-sdk-php

[examples/logiscape/server.php](examples/logiscape/server.php) serves the
same tools on [`logiscape/mcp-sdk-php`](https://github.com/logiscape/mcp-sdk-php),
the other MCP SDK for PHP many servers run on, over its stdio transport. The
SDK keeps the transport, the handshake, the stateless revision and JSON-RPC
errors; the library answers `tools/list` and `tools/call`, the two handlers
registered on the SDK's `Server`, which keys its handlers by method. What the
example needs around them is in
[examples/logiscape/bridge.php](examples/logiscape/bridge.php). The SDK runs on
PHP 8.1; the example pins its version in
[examples/logiscape/composer.json](examples/logiscape/composer.json), and a CI
job installs it there and runs
[tests/Logiscape/LogiscapeServerTest.php](../tests/Logiscape/LogiscapeServerTest.php).

```
cd docs/examples/logiscape && composer install && cd -
php docs/examples/logiscape/server.php
```

As the server over stdio written by hand, it takes the mode by name, on the
same four tools: `MCP_NOTES_MODE=grouped php docs/examples/logiscape/server.php`,
or `gateway`, `direct`, or nothing for compact. The SDK turns each answer
into its own `Result` and back, and the test suite runs every mode through
it: the annotations of each tool, the `action` enum of a group, and an empty
`arguments` object sent to the gateway's `run` all reach the client and the
library as the library wrote them.

| Part | On the SDK | Why |
|---|---|---|
| The server | the SDK's `Server`, not its `McpServer` wrapper | `McpServer` adds handlers for prompts and resources, and `initialize` would announce both to the client |
| The handlers | `registerHandler('tools/list', ...)` from `listPage()`, with the params' `cursor`, and `registerHandler('tools/call', ...)` from `callTool()` | the capability follows: a `Server` announces tools because it has a `tools/list` handler |
| The instructions | `InstructedSession`, the SDK's `ServerSession` with `instructions` set in `initialize` and `server/discover` | the SDK has no setting for them, and they are where the model reads that the list is not the whole server ([Instructions](MODES.md#instructions)); the example runs the session as the SDK's `ServerRunner` does |
| The revision | the session's `getNegotiatedProtocolVersion()`, read into `Revision::fromString()` | the SDK has already agreed on it, by handshake or by the request's `_meta` |
| The answer | `Result::fromResponseData()` on the library's array | the SDK encodes a `Result`; it removes `resultType` for an older revision and keeps it for the stateless one |
| A cursor the list did not give | `McpError` with code -32602 | any other exception from a handler reaches the client as an internal error |
| The search | one `SearchSetup`, with a `MemoryIndexCache` and a `MemoryLearningStore`, given to the `Discovery` | as over stdio by hand: the index is kept for the process, and its one caller's runs teach the search for the session |


### Over the SDK's HTTP runner

[examples/logiscape/http-server.php](examples/logiscape/http-server.php)
serves the same handlers over the SDK's `HttpServerRunner`, one process per
request as PHP-FPM runs it. The caller is no longer the process, so the gate
and the `Discovery` are built for the caller each request names, as in the
HTTP example written by hand.
[tests/Logiscape/LogiscapeHttpServerTest.php](../tests/Logiscape/LogiscapeHttpServerTest.php)
calls it over HTTP in the same CI job.

```
php -S 127.0.0.1:8080 docs/examples/logiscape/http-server.php
```

What changes from the server over stdio:

| Part | On the SDK | Why |
|---|---|---|
| The caller | read from the bearer token with `roleOf()` of `http.php`, before the SDK; no caller is answered 401 | the gate answers for one caller, and the SDK has no notion of who may see a tool |
| The handlers | `serve()`, the same two as over stdio | they read the revision from the session the runner sets on the `Server` for the request |
| The session | `sessionStore: new FileSessionStore(...)` | without a store the runner keeps sessions in memory, which a process per request loses before the client's next request; in files, the revision a client agreed on reaches its next requests |
| The instructions | `instruct()` on the runner's answer, between `handleRequest()` and `sendResponse()` | the runner builds its session itself, so `InstructedSession` cannot take its place; the JSON is decoded as objects, so that an empty object of the answer stays `{}` |
| The answer | `enable_sse` left off | the runner then answers in JSON, the body `instruct()` reads |
| The origin | `allowed_origins` set to the local host | the runner checks `Origin` only when told which hosts may send one, and the SDK's `McpServer` sets them for the built-in server alone |
| Who a session serves | anyone who sends its `Mcp-Session-Id`, unless the SDK's OAuth is on | harmless here: a session holds the revision, never a right, and the gate is built from the token of each request |
| The search | a `SearchSetup` with the `FileIndexCache` of `indexCache()`, which logs why it failed, and no learning store | as on the official SDK over HTTP: nothing in memory outlives the request, and a learning store would be shared by every caller |

The stateless revision `2026-07-28` needs no session: the runner reads its
revision from `_meta`, answers `server/discover` with the instructions, and
the handlers answer as they do for the others.

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

As the server over stdio written by hand, it takes the mode by name, on the
same four tools: `MCP_NOTES_MODE=grouped php docs/examples/sdk/server.php`,
or `gateway`, `direct`, or nothing for compact. The `sdk` test suite runs
every mode through it: the annotations of each tool, the `action` enum of a
group, a change sent to the wrong tool of its group, and an empty
`arguments` object sent to the gateway's `run` all reach the client and the
library as the library wrote them.

In your own project, `composer require mcp/sdk novalo/mcp-tool-discovery`, then:

| Part | On the SDK | Why |
|---|---|---|
| The handler | `ToolsHandler`, added with `addRequestHandler()` | it answers `ListToolsRequest` from `listPage()`, with the request's `cursor`, and `CallToolRequest` from `callTool()`; the SDK's registry of tools stays empty |
| The pages | the handler's `pageSize`, 50 by default | the SDK's own `setPaginationLimit()` default, so pass the number you give it there; a cursor the list did not give gets the SDK's `Error::forInvalidParams()` |
| The capability | `setCapabilities(new ServerCapabilities(tools: true, ...))` | with an empty registry the SDK would not announce tools by itself |
| The instructions | `setInstructions()`, with `$discovery->instructions()` after the server's own words | the SDK sends them in `initialize` and `server/discover`, where the model reads that the list is not the whole server ([Instructions](MODES.md#instructions)) |
| The revision | `RequestContext::getProtocolVersion()`, read into `Revision::fromString()` | the SDK has already agreed on it, by handshake or by the request's `_meta` |
| The arguments | decoded again as arrays | the SDK keeps nested objects as `stdClass`; without this, every item of a batch is refused as not an object |
| The answer | `Answer`, a `ResultInterface` holding the array | the SDK encodes an object; in the stateless revision it keeps the `resultType`, `ttlMs` and `cacheScope` the answer carries |
| The search | one `SearchSetup`, with a `MemoryIndexCache` and a `MemoryLearningStore`, given to the `Discovery` | as over stdio by hand: the index is kept for the process, and its one caller's runs teach the search for the session |

### Over the SDK's HTTP transport

[examples/sdk/http-server.php](examples/sdk/http-server.php) serves the same
handler over the SDK's Streamable HTTP transport, one process per request as
PHP-FPM runs it. The SDK keeps the session between requests; the caller is no
longer the process, so the gate and the `Discovery` are built for the caller
each request names, as in the HTTP example written by hand.
[tests/Sdk/SdkHttpServerTest.php](../tests/Sdk/SdkHttpServerTest.php) calls it
over HTTP in the same CI job.

```
php -S 127.0.0.1:8080 docs/examples/sdk/http-server.php
```

What changes from the server over stdio:

| Part | On the SDK | Why |
|---|---|---|
| The caller | read from the bearer token with `roleOf()` of `http.php`, before the SDK; no caller is answered 401 | the gate answers for one caller, and the SDK has no notion of who may see a tool |
| The request | `Psr17Factory::createServerRequestFromGlobals()`, and `nyholm/psr7` in `composer.json` | the transport takes a PSR-7 request; the SDK finds a PSR-17 factory but ships none |
| The session | `setSession(new FileSessionStore(...))` and a `FileSessionLock` on the same directory | without a store the SDK serves HTTP with no session at all; in files, the revision a client agreed on reaches its next requests, and two requests of one session do not overwrite each other |
| Who a session serves | the caller set as the request's `StreamableHttpTransport::PRINCIPAL_ATTRIBUTE` | the SDK answers 404 to another caller sending the session's `Mcp-Session-Id`, so one caller's gate never runs on another's session |
| The search | a `SearchSetup` with the `FileIndexCache` of `indexCache()`, which logs why it failed, and no learning store | nothing in memory outlives the request; the cache is keyed by the tools a caller may see, while a learning store would be shared by every caller, which is the host's choice, as in the ReactPHP example |

The stateless revision `2026-07-28` needs no session: the SDK reads its
revision from `_meta` and asks for the `Mcp-Method` header, and the handler
answers as it does for the others.

A client of that revision that opens a `subscriptions/listen` stream with
`toolsListChanged` is told when its caller's list changes. The SDK carries
notifications through a bus every process shares, and the host that changes
a right or a tool publishes on it that the tools changed, without knowing
whose list moved. [examples/sdk/grant.php](examples/sdk/grant.php) is that
host: it changes a role's rights in the grants file of `grantedRole()` and
publishes. [tests/Sdk/SdkListenTest.php](../tests/Sdk/SdkListenTest.php)
opens a reader's stream and an admin's, then grants and takes back.

```
MCP_NOTES_MODE=direct MCP_NOTES_GRANTS=grants.json PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 docs/examples/sdk/http-server.php
MCP_NOTES_GRANTS=grants.json php docs/examples/sdk/grant.php reader admin
```

| Part | On the SDK | Why |
|---|---|---|
| The bus | `Psr6NotificationBus` over a `FilesystemAdapter` of `symfony/cache`, given to `setNotificationBus()` | under PHP-FPM the request that changes rights and the stream are different workers: they share files, not memory |
| The stream of a caller | `CallerNotifications`, in [examples/sdk/notifications.php](examples/sdk/notifications.php), around the bus, built per request with the caller's `Discovery` | the SDK asks for its cursor when a stream opens, so the `listFingerprint()` of that moment is held; a published change is passed on once, and only when the fingerprint of the caller's rights now differs |
| The capability | `toolsListChanged` declared only when the request is of `2026-07-28` | the SDK sends these notifications on a listen stream alone; a client with a session would be promised them for nothing |
| The mode | `MCP_NOTES_MODE=direct` to watch it | in compact mode a right granted moves no line of the list, so the stream is told nothing |

A reader is never told of a tool only an admin may see, and a change in
compact mode tells no one: the bus says the tools may have changed, and the
library says for whom.

### Tools already in the SDK's registry

A server written for the SDK often has its tools there already, as handlers
added with `addTool()` or found by attribute discovery.
[examples/sdk/registry.php](examples/sdk/registry.php) keeps the first as they
are, and [examples/sdk/registry-bridge.php](examples/sdk/registry-bridge.php)
holds what it shares with the attribute server below:
the library reads their definitions from the registry and runs each one
through the SDK's own `CallToolHandler`, so a tool found by search runs as a
direct call would. Its arguments are checked against the schema the SDK
derived, its return value is formatted by the SDK, and a `ToolCallException`
becomes an error result. [tests/Sdk/SdkRegistryTest.php](../tests/Sdk/SdkRegistryTest.php)
runs it in the same CI job.

```
php docs/examples/sdk/registry.php
```

What changes from the server above:

| Part | On the SDK | Why |
|---|---|---|
| The registry | `new Registry()` given to `setRegistry()`, and the same `ReferenceHandler` to `setReferenceHandler()` | the handler reads the tools the SDK registered, and runs them with the handler that would have run them |
| The tools | `RegistryInterface::getTools()`, each `Tool` encoded and read with `Tool::fromArray()` | the definitions the client would have seen, in the registry's order |
| The gate | `RegistryGate`, built per request with its session | `call()` hands a `CallToolRequest` with the client's id and `_meta` to the SDK's `CallToolHandler`, which reads the revision from them; who sees which tool stays the host's rule |
| The errors | an `Error` from the SDK becomes `CallResult::error()` | arguments the schema refuses are a protocol error for the SDK; through a mode they are the tool's answer, which the assistant reads and corrects |
| The search | the same `SearchSetup`, built once and given to the handler | the gate and the `Discovery` are built per request, the cache and what was learned outlive it |
| The mode | built once, given to the handler and read for `setInstructions()` | the builder needs the instructions before any request, so before any `Discovery`; a mode implementing `Mode\Instructions` gives them itself |
| The capability | detected by the SDK | the registry is not empty, so the SDK announces tools by itself |

The handler answers every `tools/call`, so a tool the gate hides is unknown
even when a client calls it by its own name. A tool that asks the client for
input (`InputRequiredResult`) cannot run through a mode: the question and its
answer travel between the SDK and the client, and the example answers it as
an error.

### Tools found by their attribute

[examples/sdk/attributes.php](examples/sdk/attributes.php) serves the same
tools as methods of one class,
[examples/sdk/tools/NoteTools.php](examples/sdk/tools/NoteTools.php), each
marked `#[McpTool]`. The SDK finds them, takes each description from the doc
comment and each parameter's from its `@param`, and they reach the same
registry, so the handler and the gate are the ones above, unchanged.
[tests/Sdk/SdkAttributesTest.php](../tests/Sdk/SdkAttributesTest.php) holds
it to every test of the registry server.

```
php docs/examples/sdk/attributes.php
```

What changes from the registry server:

| Part | On the SDK | Why |
|---|---|---|
| The discovery | `setDiscovery(__DIR__, ['tools'])`, and `symfony/finder` in `composer.json` | the SDK needs Finder to read a directory and suggests it without requiring it |
| The class | autoloaded by Composer (`autoload.psr-4` of the example) | the SDK reads the class from its file and asks the autoloader for it |
| The container | one `Container`, given the server's `Notes` with `set()`, passed to `setContainer()` and to the `ReferenceHandler` | the class of the tools needs its `Notes`; a `ReferenceHandler` built without the container creates the class with `new` and no argument, and every call fails |

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
- **Arguments as `json_decode()` gives them.** Check that `arguments` is an
  array before calling `callTool()`, as every example does. A JSON array
  passes that check too; the library refuses it with `isError` before your
  gate sees it, so `authorize()` and `call()` always get named arguments.
- **A list that is still right.** When the tools change, the next request
  sees them; the list a client already holds may not, depending on the mode
  ([When the tools change](#when-the-tools-change)).
- **Instructions that say how to reach the tools.** In every mode but
  Direct, the list is not the whole server. Add `$discovery->instructions()`
  after your own words in the `instructions` of the `initialize` answer, or
  of `server/discover`, as the stdio and HTTP examples do
  ([Instructions](MODES.md#instructions)).
- **Pages, if the list is long.** `listTools()` answers in one piece.
  A host that pages its list calls `listPage()` instead
  ([A list in pages](#a-list-in-pages)).

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

---

## A list in pages

`tools/list` may be paged: the client sends the `cursor` of the last answer,
and the server answers with a `nextCursor` while tools remain. A client reads
every page before it uses the list, so paging saves no token; it keeps each
answer short, for a transport or a proxy that limits its size, or a server in
direct mode with hundreds of tools.

```php
use Novalo\McpToolDiscovery\Exception\InvalidCursor;

try {
    $result = $discovery->listPage($tools, $revision, $params['cursor'] ?? null, 50, ttlMs: 300000);
} catch (InvalidCursor $error) {
    // The specification asks for invalid params.
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => $error->getMessage()]];
}
```

The pages put together are the answer of `listTools()`, in every mode and
every revision, each one with the same `ttlMs` and `cacheScope`. The cursor
names the last tool of its page rather than a position, so a tool that comes
or goes elsewhere in the list between two pages neither skips nor repeats
one. A cursor whose tool has gone, or that names a tool the gate hides from
this caller, is refused with `InvalidCursor`, in the same words as a cursor
the list never gave: the client starts again from the first page. The
modes other than direct list a handful of tools, where a page of 50 is the
whole list and carries no `nextCursor`.
[ListPageTest](../tests/Unit/ListPageTest.php) holds all of this. The
examples on the official SDK page this way, through the SDK's own request,
and the SDK tests hold a refused cursor to error -32602.

---

## When the tools change

A module installed, a permission granted, a tool renamed: the library holds
nothing from one request to the next, so the `ToolSet` and the gate a request
hands over are the whole truth for that request. A search finds a new tool at
once, and a tool that went away, or that the gate now hides, is refused at
once, whatever an earlier search showed or the learning store kept.
[ToolsChangeTest](../tests/Unit/ToolsChangeTest.php) holds this and the table
below.

What the client may still hold is its copy of `tools/list`. Whether that copy
is now wrong depends on the mode:

| Mode | Its list changes when | Its list stays the same when |
|---|---|---|
| Compact | a pinned tool comes, goes or changes | any other tool comes, goes or changes: it is found by a search |
| Gateway | the first tool that changes something comes or the last one goes; with `changesApart`, any tool that changes something comes, goes or changes | a tool that only reads comes, goes or changes |
| Grouped | a tool outside the groups changes in any way, or a grouped one comes, goes, or changes its name, its title, or whether it only reads | a grouped tool changes only its description or its schema: `describe` gives it as it is now |
| Direct | any tool changes in any way | never |

A host need not know this table by heart: `listFingerprint()` gives a short
string that changes when, and only when, the list a caller would be given
changes. It covers every page at once and leaves out `nextCursor`, `ttlMs`
and `cacheScope`, so a host that pages its list compares the whole of it,
not the page it last answered. It is taken for the client's revision: a
title changed for a client of `2024-11-05`, whose tools have none, tells
it nothing. To keep a client's list right:

- **With a session**, declare `"tools": {"listChanged": true}` in the
  capabilities of `initialize`, keep the `listFingerprint()` of the list
  the session was given, and send `notifications/tools/list_changed` when
  the next one differs. In compact mode that is rare: hundreds of tools can
  come and go behind the mode's own, which never move. The
  [stdio server](#a-server-over-stdio) does this, and its tests grant and take
  back a role while a client is connected: in direct mode the client is told
  once each time, in compact mode never, and its next search finds the tool.
  The [ReactPHP server](#a-server-that-stays-up-on-reactphp) does it over
  HTTP, on the stream a session opens with `GET`.
- **In the stateless revision `2026-07-28`**, the same notification goes only
  on a `subscriptions/listen` stream the client opened with
  `toolsListChanged`, and the capability is declared in `server/discover`.
  A client that opened none keeps its copy for the `ttlMs` given to
  `listTools()`: a short one costs a list now and then, a long one is safe in
  compact mode as long as the pinned tools stay as they are. The
  [server on the SDK's HTTP transport](#over-the-sdks-http-transport) does
  this: the host publishes that the tools changed, and each stream compares
  the `listFingerprint()` of its own caller.

In a session, it takes a few lines around the handlers the host already has:

```php
// When the session answers tools/list, whether in one piece or in pages:
$session['tools'] = $discovery->listFingerprint($tools, $revision);

// When the next message of the session comes, before answering it:
if (isset($session['tools']) && $discovery->listFingerprint($tools, $revision) !== $session['tools']) {
    $send(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']);
    // Told once: the client asks again, and the list it gets is held then.
    $session['tools'] = $discovery->listFingerprint($tools, $revision);
}
```

A cached index is keyed by the tools it holds, so a change builds a new one on
the next search, and the old one is read again only if the tools go back to
what they were. `MemoryIndexCache` drops
the oldest past its size. `FileIndexCache` leaves the old files where they
are: emptying its directory when the server is deployed is safe, and the next
search writes the index it needs. What the learning store kept for a tool
only reorders tools a search already found, so a removed tool's counts lift
nothing; a renamed tool starts learning again under its new name.
