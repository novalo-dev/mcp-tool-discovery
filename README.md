# MCP Tool Discovery for PHP

<div align="center">

[![CI](https://github.com/novalo-dev/mcp-tool-discovery/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/novalo-dev/mcp-tool-discovery/actions/workflows/ci.yml)
[![Conformance](https://github.com/novalo-dev/mcp-tool-discovery/actions/workflows/conformance.yml/badge.svg?branch=develop)](https://github.com/novalo-dev/mcp-tool-discovery/actions/workflows/conformance.yml)
[![Security](https://github.com/novalo-dev/mcp-tool-discovery/actions/workflows/security.yml/badge.svg?branch=develop)](https://github.com/novalo-dev/mcp-tool-discovery/actions/workflows/security.yml)

[![Packagist](https://img.shields.io/packagist/v/novalo/mcp-tool-discovery?label=packagist&color=007EC6)](https://packagist.org/packages/novalo/mcp-tool-discovery)
[![PHP](https://img.shields.io/badge/PHP-8.1%20to%208.5-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-2A5EA7)](phpstan.dist.neon)
[![Search](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnovalo-dev%2Fmcp-tool-discovery%2Fbadges%2Fsearch.json)](docs/SEARCH.md)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
[![Sponsor](https://img.shields.io/badge/sponsor-novalo--dev-EA4AAA?logo=githubsponsors&logoColor=white)](https://github.com/sponsors/novalo-dev)

**Show an assistant three tools instead of three hundred, and let it find the one it needs.**

Tool discovery for any PHP MCP server - zero dependencies - your access rules, never ours

[Install](#install) · [Quick start](#quick-start) · [Modes](#modes) · [Architecture](docs/ARCHITECTURE.md) · [Contributing](CONTRIBUTING.md)

</div>

Every tool a server lists is sent to the model with every request. Past a
hundred tools that costs tokens, crowds the model's choice, and some clients
simply stop at a fixed number. This library sits between your server and its
tools: it decides **how** the tools are shown to the client, while your code
keeps deciding **who** may see and run each one.

It is plain PHP arrays in, plain PHP arrays out, in the exact shape of the MCP
specification. It works with the official PHP SDK, with a framework, or with a
`tools/list` handler written by hand.

---

## Protocol conformance

Every answer the library builds is validated in CI against the official
`schema.json` of each revision of the specification, read from a pinned commit
of the specification repository. Scores are passing checks out of total.

| Revision | Era | Conformance |
|---|---|---|
| `2026-07-28` | stateless | [![2026-07-28](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnovalo-dev%2Fmcp-tool-discovery%2Fbadges%2Fconformance-2026-07-28.json)](docs/ARCHITECTURE.md#protocol-revisions) |
| `2025-11-25` | `initialize` handshake | [![2025-11-25](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnovalo-dev%2Fmcp-tool-discovery%2Fbadges%2Fconformance-2025-11-25.json)](docs/ARCHITECTURE.md#protocol-revisions) |
| `2025-06-18` | `initialize` handshake | [![2025-06-18](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnovalo-dev%2Fmcp-tool-discovery%2Fbadges%2Fconformance-2025-06-18.json)](docs/ARCHITECTURE.md#protocol-revisions) |
| `2025-03-26` | `initialize` handshake | [![2025-03-26](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnovalo-dev%2Fmcp-tool-discovery%2Fbadges%2Fconformance-2025-03-26.json)](docs/ARCHITECTURE.md#protocol-revisions) |
| `2024-11-05` | `initialize` handshake | [![2024-11-05](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnovalo-dev%2Fmcp-tool-discovery%2Fbadges%2Fconformance-2024-11-05.json)](docs/ARCHITECTURE.md#protocol-revisions) |

Each answer is cut to the fields its revision defines: a client on
`2025-03-26` never receives an `outputSchema` it does not know, and the
`2026-07-28` list carries the `ttlMs`, `cacheScope` and `resultType` that
revision requires. Empty PHP arrays that must be JSON objects (a tool with no
parameter, `"properties": {}`) are sent as objects, a mistake every PHP server
makes once.

---

## Install

```
composer require novalo/mcp-tool-discovery
```

PHP 8.1 to 8.5. No extension beyond what PHP always has, no dependency.

---

## Quick start

**1. Say who may do what.** The library never decides access. You implement
one interface for the caller of the current request:

```php
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Tool\Tool;

final class MyGate implements ToolGate
{
    public function __construct(private User $user, private MyTools $tools) {}

    // Hidden tools are never listed, found by a search, or run.
    public function visible(Tool $tool): bool
    {
        return $this->user->can($tool->name);
    }

    // Asked again at call time, with the arguments. Null lets the call go.
    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        return $this->user->can($tool->name, $arguments) ? null : CallResult::error('Not allowed.');
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        return CallResult::fromArray($this->tools->run($tool->name, $arguments));
    }
}
```

**2. Hand over your tools** - the same definitions your `tools/list` already
builds:

```php
use Novalo\McpToolDiscovery\Tool\ToolSet;

$tools = ToolSet::of($myToolDefinitions); // list of MCP Tool arrays
```

A malformed definition, or a second tool under a name already taken, throws
`InvalidTool`. If your tools come from parts you do not write (plugins,
other packages), pass a callback as the second argument,
`ToolSet::of($myToolDefinitions, $refused)`: such a tool is left out, the
first one under a name is kept, and `$refused` gets the `InvalidTool` and
the definition, to log.

**3. Answer the two methods** with a mode:

```php
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;

$discovery = new Discovery(new CompactMode(), new MyGate($user, $myTools));

// initialize: keep the revision for the session, and tell the model how to
// reach the tools the list leaves out, after your own words
$revision = Revision::negotiate($params['protocolVersion'] ?? null);
$initialize = [
    'protocolVersion' => $revision->value,
    'capabilities' => ['tools' => new \stdClass()],
    'serverInfo' => ['name' => 'my-server', 'version' => '1.0.0'],
    'instructions' => trim('Files and tickets of the team. ' . $discovery->instructions()),
];

// tools/list
$result = $discovery->listTools($tools, $revision, ttlMs: 300000);

// tools/call
$result = $discovery->callTool($params['name'], $params['arguments'] ?? [], $tools, $revision);
```

Both return the `result` member of the JSON-RPC answer. Your server keeps the
envelope, the transport and the session. Without the `instructions`, a model
shown three tools for a server of three hundred concludes the one it needs is
missing; in the stateless revision `2026-07-28` they go in the
`server/discover` answer instead ([docs/HOSTS.md](docs/HOSTS.md)). A host that pages `tools/list`
calls `listPage()` with the request's `cursor` instead. These blocks run in CI as they are
written here ([tests/Docs/QuickStartTest.php](tests/Docs/QuickStartTest.php)). [docs/HOSTS.md](docs/HOSTS.md) shows
complete servers that the tests run as a client would: two written by hand,
one over stdio and one over HTTP with a gate built for each request, the same
HTTP server kept up in one ReactPHP process, the same as a Symfony application
and as a Laravel one, a server written with laravel/mcp whose `tools/list`
and `tools/call` the library answers, and four on the official PHP SDK: over stdio and over
its HTTP transport, and two with their tools left in the SDK's registry,
added by hand or found by their attribute.

**4. Optionally, tune the search**, once, for whichever mode you use. The
cache and the learning store are both optional:

```php
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\FileLearningStore;
use Novalo\McpToolDiscovery\Search\SearchOptions;
use Novalo\McpToolDiscovery\Search\SearchSetup;

$discovery = new Discovery(new CompactMode(), new MyGate($user, $myTools), new SearchSetup(
    options: new SearchOptions(synonyms: ['meeting' => 'calendar event']),
    cache: new FileIndexCache('/var/cache/mcp-index'),            // build the index once
    learning: new FileLearningStore('/var/lib/mcp/learning.json'), // learn from successful runs
));
```

A server that changes modes keeps its settings, its cache and what it learned.

---

## Modes

A mode decides what the client sees. Each mode is a **pack** of its own in
`src/Mode/<Name>/`: a server that uses one mode loads that pack's classes and
nothing else, and every pack is held to the same
[contract tests](tests/Contract/ModeContractTestCase.php).

| Mode | The client sees | State |
|---|---|---|
| **Compact** | a search tool and two runners: three tools, whatever the size of the server | shipped |
| **Grouped** | one tool per group of tools ("files", "calendar") listing its actions, and one for its changes | shipped |
| **Gateway** | a single tool that searches, describes and runs everything | shipped |
| **Direct** | every tool as itself, as a server without the library lists it | shipped |

A host tool called by its own name is accepted in every mode, through the same
checks: a client holding a list from before the server switched modes keeps
working. [Choosing a mode](docs/MODES.md#choosing-a-mode) gives what each
list costs on the benchmark servers: on the one with 176 tools, 60,640 bytes
for every tool as itself, 2,871 for the compact list.

### Compact

The client receives three tools, four with the batch runner:

| Tool | What it does | Annotations |
|---|---|---|
| `search_tools` | finds the tools that match a request given in fields (action, object, description) or as keywords, or describes tools by name, with their `inputSchema` and the runner each one goes through; with no argument, lists every tool by name | reads only |
| `run_read_tool` | runs a tool that only reads | reads only |
| `run_write_tool` | runs a tool that changes something | destructive |
| `run_write_batch` | runs one tool that changes something on a list of items, in one call; only with `batchLimit` | destructive |

Two runners rather than one: `destructiveHint` is the only lever a client has
to ask the person before a call. A change sent to the read runner, or a read
sent to the change runner, is refused, so a change never slips past that
confirmation. A runner never runs a runner or the search.

A client that must change thirty items would make, and confirm, thirty calls.
With `batchLimit` the mode adds a fourth tool, `run_write_batch`: one change
tool, a list of `items` (the arguments of each run), one call and one
confirmation of the whole list. It is destructive like `run_write_tool`, and
every item meets your gate on its own, exactly as a single call: a hidden
tool, a read or a refused item never runs because it came in a list. The
answer reports each item, and one failure never hides the others.

```php
$mode = new CompactMode(
    prefix: 'acme_',                 // acme_search_tools, acme_run_read_tool, ...
    pinned: ['acme_ping'],           // host tools listed as themselves
    titles: ['search' => 'Rechercher les outils'], // titles, translated by you
    groupOf: fn (Tool $t): string => $myGroups[$t->name] ?? 'other', // groups the no-argument list; every tool needs one
    batchLimit: 50,                  // adds run_write_batch, up to 50 items a call; 0 (default) leaves it out
);
```

An MCP prompt that names tools should tell the client how to reach them, in
every mode but Direct: `$discovery->guideHeader($toolNames, $tools)` returns
the block to put at the top of the prompt text, written for the current mode
and naming only the tools the caller may see
([docs/MODES.md](docs/MODES.md#guides)).
The server's instructions should say it too, since clients read them
before any tool: `$discovery->instructions()` gives the sentences to add to
the `instructions` of the `initialize` answer, the same for every caller
([docs/MODES.md](docs/MODES.md#instructions)).

### Grouped

The client sees the map of the server without searching: for each group you
declare, one tool named after it that runs its reads and describes its
actions, and `<group>_change`, destructive, for its changes.

```php
use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;

$mode = new GroupedMode(
    groupOf: fn (Tool $t): string => $myGroups[$t->name] ?? '', // '' lists the tool as itself
    groups: ['files' => 'Files and folders', 'calendar' => 'Calendar'], // name => title
    prefix: 'acme_',                 // acme_files, acme_files_change, ...
);
```

```json
{"action": "acme_get_file", "arguments": {"file_id": "42"}}
{"describe": ["acme_move_file"]}
```

[docs/MODES.md](docs/MODES.md#grouped) explains the choices.

### Gateway

One tool, `use_tools`, for clients that accept very few: every call says its
`operation`, `search`, `describe` or `run`, and the search is the compact one.

```php
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;

$mode = new GatewayMode(
    prefix: 'acme_',                 // acme_use_tools
    changesApart: true,              // changes listed as themselves; the gateway only reads
);
```

```json
{"operation": "search", "action": "change", "object": "ticket status"}
{"operation": "run", "name": "acme_set_ticket_status", "arguments": {"ticket_id": "7", "status": "done"}}
```

The tool is marked destructive whenever a change can go through it, so the
client asks first, and each tool a search finds that changes something says
`"changes": true`, so the model knows which calls to confirm; with
`changesApart` it only reads, and each change keeps its own annotations. [docs/MODES.md](docs/MODES.md#gateway) explains the
choice.

### Direct

Every tool the caller may see, as itself, and no tool of the mode's own. For
a server small enough to list everything, or a setting that lets the server's
users turn the other modes off, without a second path for `tools/list` and
`tools/call` that skips your gate's `visible()` or the shaping per revision:

```php
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;

$mode = $settings->compact ? new CompactMode() : new DirectMode();
$discovery = new Discovery($mode, new MyGate($user, $myTools));
```

---

## Search

The search behind `search_tools` is a BM25F ranking built for tool lists. The
client gives its request **in fields**, and each field is scored on its own:

```json
{"action": "change", "object": "ticket status", "description": "move the ticket to done"}
```

- **Text relevance** over the name, title, description and parameters of each
  tool, each with its weight. A rare word counts more than a common one, so a
  prefix or a verb every tool shares weighs almost nothing.
- **Whole words and trigrams**, so `statuses` meets `status` and `categories`
  meets `category` without a stemmer. A word typed without its marks
  ("hoc phi", "счет") meets the tool word it spells ("học phí", "счёт"), with no table of
  letters and no extension. Chinese, Japanese, Thai, Lao and Burmese,
  written without spaces, are cut into pairs of characters, with no dictionary, and
  so is each Korean word, which carries its particles.
- **No list, no convention.** No stop words, no table of verbs, no prefix
  rule, in any language: snake_case, camelCase and dotted names are all read
  the same way. Synonyms are the host's, when it has some.
- **Words to say it again.** Words no tool uses come back with the words of
  the server's own tool titles, so the client rephrases instead of guessing.
- **A cut** at 60% of the best score, so the client receives a few schemas.
- **A cache** for the index (in memory, or PHP files read through OPcache),
  one per set of visible tools.
- **Learning, opt in.** A search returns a `search_id`; a run that succeeds
  with it lifts that tool for those pairs of words, within a cap. Only words
  the tools use are kept: a word written another way is kept as the tool
  word closest to it.
- **A log, opt in.** A `SearchLog` is told each search and each run after
  it, under one id, so a host sees what its clients asked and missed. The
  library writes nothing itself ([docs/SEARCH.md](docs/SEARCH.md#log)).

3145 requests over twenty-eight fictional servers, a workspace (snake_case), a
developer platform (camelCase), people operations (dotted names), a network
of public libraries written in French (kebab-case), the office of a school
written in German (PascalCase), a cloud hosting panel with 176 tools, the
service desk of a town hall written in Greek, a newsroom whose tools have
no title and long descriptions, as many real servers write them, the
office of a residential estate written in Chinese, a bus depot written
in Hindi, a sports centre written in Thai, a music school written in
Korean, a car repair workshop written in Arabic, the front desk of a
hotel written in Russian (SCREAMING_SNAKE_CASE), a city theatre written
in Ukrainian, a marina written in Hebrew (Train-Case), a veterinary clinic
written in Japanese, a water supply office written in Khmer, a post
office written in Lao, a rice
mill written in Burmese, an archaeological museum written in Greek, a
city bike rental written in Polish, an airline operations centre with
150 tools, many of them neighbours, a fleet of field sensors whose
tools were generated from an OpenAPI description, a ferry line
written in Turkish, a kindergarten written in Vietnamese, a fire
station written in Spanish and a driving school written in Persian.
Half were used to choose the settings; the other half, kept aside, gives the
score:

| Test half, 1576 requests | Right tool first | Right tool in the top 3 |
|---|---|---|
| The request in fields | **1201** | **1406** |
| The same request as one sentence | 900 | 1164 |
| In fields, after learning from the other half | 1204 | 1406 |
| In fields, asked a second time | 1317 | 1466 |

The same requests ranked by the search of laravel/mcp, Laravel's official MCP
package, which a PHP host may already have: a substring score on the name,
the description and the input schema, with no title, no index and no
learning (`tests/Fixtures/SubstringSearch.php`). It takes one string, so the
fields are joined into one:

| The same half, ranked as laravel/mcp v1.2.1 ranks | Right tool first | Top 3 |
|---|---|---|
| The request as one sentence | 486 | 736 |
| The fields joined into one sentence | 870 | 1123 |

A search over 50 tools takes about 2 ms with no cache and a tenth of a
millisecond with one; over 500 tools, about 1 ms with a cache. [docs/SEARCH.md](docs/SEARCH.md) explains the ranking, the measures
and how to change them.

---

## Security model

- **Your gate decides, always.** The library holds no notion of user, token or
  permission.
- **Hidden means absent.** The visible set is computed once, before the mode
  sees anything. A hidden tool is never listed, never found, never described,
  and asking for it by name gives the same answer as a name that does not exist.
- **No run without authorization.** A mode never holds your gate: it receives
  a runner that only runs visible tools, and only after `authorize()`.
- **Changes are flagged.** A tool counts as a read only with an explicit
  `readOnlyHint: true`; anything else goes through the destructive runner.

The [contract tests](tests/Contract/ModeContractTestCase.php) check these rules
on every mode. Report a vulnerability privately, as [SECURITY.md](SECURITY.md)
explains.

---

## Documentation

| Document | For |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | the layers, the flow of a call, the protocol revisions |
| [docs/HOSTS.md](docs/HOSTS.md) | wiring the library into a server, with complete examples over stdio, HTTP, a ReactPHP process that stays up, a Symfony application, a Laravel application, laravel/mcp and the official PHP SDK, over stdio or HTTP, with tools outside or inside its registry |
| [docs/MODES.md](docs/MODES.md) | choosing a mode, what each costs, how a mode works, writing one |
| [docs/SEARCH.md](docs/SEARCH.md) | the ranking, the benchmark, tuning |
| [CONTRIBUTING.md](CONTRIBUTING.md) | setup, branches, checks, pull requests |
| [ROADMAP.md](ROADMAP.md) | the work wanted: modes, search, hosts |
| [CHANGELOG.md](CHANGELOG.md) | what changed, version by version |

---

## Contributing

Contributions go to `develop`, through pull requests; `main` only receives
releases. New modes are the most wanted. Read [CONTRIBUTING.md](CONTRIBUTING.md)
first: every pull request runs the coding standard, PHP 8.1+ compatibility,
PHPStan at level max, the tests on PHP 8.1 to 8.5, the contract tests, the
conformance suite, the search benchmark, Semgrep, Psalm's taint analysis and
Gitleaks. Contributions are published under the MIT licence, with nothing to
sign. The [roadmap](ROADMAP.md) lists the work wanted;
[sponsors](https://github.com/sponsors/novalo-dev) have their issues read first.

## License

MIT, see [LICENSE](LICENSE). Made by [Novalo](https://novalo.fr).
