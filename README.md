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

**3. Answer the two methods** with a mode:

```php
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;

$discovery = new Discovery(new CompactMode(), new MyGate($user, $myTools));
$revision = Revision::fromString($negotiatedProtocolVersion);

// tools/list
$result = $discovery->listTools($tools, $revision, ttlMs: 300000);

// tools/call
$result = $discovery->callTool($params['name'], $params['arguments'] ?? [], $tools, $revision);
```

Both return the `result` member of the JSON-RPC answer. Your server keeps the
envelope, the transport and the session.

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
| **Grouped** | one tool per group of tools ("files", "calendar"), each listing its actions | open to contribution |
| **Gateway** | a single tool that searches and runs everything | open to contribution |

A host tool called by its own name is accepted in every mode, through the same
checks: a client holding a list from before the server switched modes keeps
working.

### Compact

The client receives three tools:

| Tool | What it does | Annotations |
|---|---|---|
| `search_tools` | finds the tools that match a request given in fields (action, object, description) or as keywords, or describes tools by name, with their `inputSchema` and the runner each one goes through; with no argument, lists every tool by name | reads only |
| `run_read_tool` | runs a tool that only reads | reads only |
| `run_write_tool` | runs a tool that changes something | destructive |

Two runners rather than one: `destructiveHint` is the only lever a client has
to ask the person before a call. A change sent to the read runner, or a read
sent to the change runner, is refused, so a change never slips past that
confirmation. A runner never runs a runner or the search.

```php
new CompactMode(
    prefix: 'acme_',                 // acme_search_tools, acme_run_read_tool, ...
    pinned: ['acme_ping'],           // host tools listed as themselves
    titles: ['search' => 'Rechercher les outils'], // titles, translated by you
    groupOf: fn (Tool $t) => $myGroups[$t->name], // groups the no-argument list
);
```

An MCP prompt that names tools should tell the client they are not in its
list: `$mode->guideHeader($toolNames, $visible)` returns the block to put at
the top of the prompt text.

### Grouped and Gateway

Not written yet, and wanted. [docs/MODES.md](docs/MODES.md) describes what each
should do and how to build a mode; the contract tests say what it must never
do.

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
  meets `category` without a stemmer.
- **No list, no convention.** No stop words, no table of verbs, no prefix
  rule, in any language: snake_case, camelCase and dotted names are all read
  the same way. Synonyms are the host's, when it has some.
- **Words to say it again.** Words no tool uses come back with the words of
  the server's own tool titles, so the client rephrases instead of guessing.
- **A cut** at 60% of the best score, so the client receives a few schemas.
- **A cache** for the index (in memory, or PHP files read through OPcache),
  one per set of visible tools.
- **Learning, opt in.** A search returns a `search_id`; a run that succeeds
  with it lifts that tool for those words, within a cap. Only words the tools
  use are kept.

300 requests over three fictional servers, a workspace (snake_case), a
developer platform (camelCase) and people operations (dotted names). Half were
used to choose the settings; the other half, kept aside, gives the score:

| Test half, 150 requests | Right tool first | Right tool in the top 3 |
|---|---|---|
| The request in fields | **125** | **140** |
| The same request as one sentence | 57 | 79 |
| In fields, after learning from the other half | 127 | 140 |
| In fields, asked a second time | 133 | 142 |

A search over 50 tools takes about 2 ms with no cache and half a millisecond
with one. [docs/SEARCH.md](docs/SEARCH.md) explains the ranking, the measures
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
| [docs/MODES.md](docs/MODES.md) | how a mode works, the modes wanted, writing one |
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
