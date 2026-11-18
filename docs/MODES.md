# Modes

A mode decides what a client sees in `tools/list` and how calls to the mode's
own tools reach the host's tools. Four are written:

| Mode | Pack | State |
|---|---|---|
| Compact | `src/Mode/Compact/` | shipped |
| Grouped | `src/Mode/Grouped/` | shipped |
| Gateway | `src/Mode/Gateway/` | shipped |
| Direct | `src/Mode/Direct/` | shipped |

---

## Choosing a mode

A client sends the list of tools to the model with every request, so the list
is the cost that repeats. A search answer enters the conversation once, when
the client looks for a tool. Measured on the benchmark servers, in bytes of
JSON on `2025-11-25` (`composer benchmark` prints this table, and
[ListCostTest](../tests/Search/ListCostTest.php) guards what it shows):

| Server | Tools | Direct (every tool as itself) | Compact | Gateway | Grouped | One compact search |
|---|---|---|---|---|---|---|
| workspace | 50 | 16,147 | 2,871 | 1,845 | 14,314 | 1,125 |
| platform | 40 | 12,829 | 2,871 | 1,845 | - | 862 |
| people | 40 | 12,814 | 2,871 | 1,845 | - | 874 |
| library | 40 | 14,550 | 2,871 | 1,845 | - | 1,069 |
| school | 40 | 15,096 | 2,871 | 1,845 | - | 1,076 |
| hosting | 176 | 60,640 | 2,871 | 1,845 | - | 1,345 |
| city | 40 | 19,334 | 2,871 | 1,845 | - | 1,428 |
| newsroom | 40 | 20,067 | 2,871 | 1,845 | - | 1,677 |
| estate | 40 | 12,284 | 2,871 | 1,845 | - | 813 |
| depot | 40 | 17,134 | 2,871 | 1,845 | - | 1,375 |

The search is the mean over the test half of the benchmark requests, text
content only: the structured copy carries the same data. For English JSON a
token is about four bytes. The fixture tools are short, about 300 bytes each,
except the newsroom's, about 500 bytes, written as many real servers write
them; tools with longer descriptions and schemas widen every gap. Only the
workspace declares groups, in
[tests/Fixtures/Groups.php](../tests/Fixtures/Groups.php), and 22 of its 50
tools stay outside them, listed as themselves.

- **Compact** is the default. Its list is the same size whatever the server,
  under a quarter of the whole list from 40 tools, and the list with one
  search stays under half of it. Each change keeps the confirmation of a
  destructive tool.
- **Gateway** is the smallest list, for a client that caps its tools very low
  or a small model that handles one tool better than three. Its single tool
  is destructive as soon as a change goes through it, so a client that asks
  before each destructive call asks before searches too, unless the host sets
  `changesApart`.
- **Grouped** shows the map of the server without a search, for a client that
  searches badly and a server whose tools fall into a few clear groups. It
  leaves the schemas out but names every action, so its list grows with the
  server. On the workspace its eight tools take 7,486 bytes where the 28
  actions they stand for would take 9,299: on short tools it saves about a
  fifth, more as the schemas grow.
- **Direct** lists every tool as itself, the first column. It suits a server
  with a few tools, where a search would cost more than it saves, and the
  host that lets its users turn the other modes off.

---

## The contract

```php
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Tool\ToolSet;

interface DiscoveryMode
{
    public function name(): string;
    public function listTools(ToolSet $visible): array;          // list<Tool>
    public function owns(string $name): bool;
    public function call(string $name, array $arguments, ToolRunner $runner): CallResult;
}
```

- `listTools()` receives only the tools the caller may see. It returns the
  mode's own tools, some of the visible ones, or both, and nothing else.
- `owns()` says which names are the mode's. `Discovery` sends those calls to
  `call()`, and every other name straight to the host tool, through the same
  checks.
- `call()` reaches host tools only through `$runner->run()`, which refuses a
  tool outside the visible set and arguments that are not an object, and asks
  the host's `authorize()` first. Check the arguments a mode reads from its
  own call with `ToolRunner::isObject()`, the same rule: a JSON array decodes
  to a PHP array too.
- A mode that searches calls `$runner->search()`, never a `ToolSearch` of its
  own: the host's settings, cache and learning store come with it. A mode
  that hands out a `search_id` passes it back to `$runner->run()`, which
  learns only from a visible, authorized run that succeeded.
- Build your tool definitions with `Tool::fromArray()`, so they are validated,
  and let `Shaper` adapt them to each revision.

The rules a mode must keep are in
[ModeContractTestCase](../tests/Contract/ModeContractTestCase.php). Read it
before writing a line: it is the review your mode will get.

---

## Compact

Three tools: `search_tools`, `run_read_tool`, `run_write_tool`, and a fourth,
`run_write_batch`, when the host turns it on.

- `search_tools` takes `action`, `object` and `description` (the request in
  fields), `query` (free keywords) and `names` (exact names). Names are
  described first, then the search fills up to `maxDescribed` (10). Each result
  has `name`, `title`, `run_with`, `description` and `inputSchema`. Names the
  caller may not use come back in `unknown`, as names that do not exist do.
  Words no visible tool uses come back in `unmatched`, with `vocabulary`, the
  words of the visible tools' titles, so the client can say it again.
  With a learning store, the answer carries a `search_id`.
  With no argument it lists every visible tool, names only, grouped when the
  host gives `groupOf`.
- `run_read_tool` and `run_write_tool` take `name` and `arguments`, and
  `search_id` when the host learns: a run that succeeds with it teaches the
  search which tool answered those words. Each refuses
  the other kind of tool, and both refuse the mode's own tools. A failed run
  carries the tool's parameters, so the client can correct its call.
- `run_write_batch`, off by default, runs one change tool once per item of a
  list in a single call: `name`, `items` (one object of arguments per item)
  and `search_id`. The host turns it on with `batchLimit`, the most items one
  call takes. Each item goes through `$runner->run()` on its own, so the
  gate's `authorize()` and `call()` see it exactly as a single call; the list
  is checked first (an object per item, within the limit, a change tool the
  caller may see) and nothing runs when it is malformed. The answer gives
  `succeeded`, `failed` and one entry per item with its `isError`, `content`
  and `structuredContent`, and is an error only when no item succeeded. It
  is marked `destructiveHint`, and its description asks the client to show
  the whole list before the one confirmation. The search learns once per
  batch, from the first item that succeeds.
- `pinned` tools are listed as themselves, next to the three.
- `guideHeader()` gives the block to put at the top of a prompt that names
  tools, saying which runner each one goes through.

## Grouped

One tool per group of host tools - `files`, `calendar`, `tickets` - so the
client sees the map of the server without searching, in tens of tools rather
than hundreds.

```php
use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;
use Novalo\McpToolDiscovery\Tool\Tool;

$mode = new GroupedMode(
    groupOf: fn (Tool $t): string => $myGroups[$t->name] ?? '',
    groups: ['files' => 'Files and folders', 'calendar' => 'Calendar'], // name => title, in list order
    prefix: 'acme_',                     // acme_files, acme_files_change, ...
    changeTitles: ['files' => 'Change files'], // optional; "<title>: changes" otherwise
);
```

Each declared group that holds at least one visible tool becomes:

| Tool | What it does | Annotations |
|---|---|---|
| `<group>` | runs one of the group's reads (`action`, `arguments`), or describes any of its actions, reads or changes, with their `inputSchema` (`describe`: a list of names); its description lists each of its reads in one line, and says which tool runs the changes | reads only |
| `<group>_change` | runs one of the group's changes (`action`, `arguments`); listed only when the group has a change the caller may see | destructive |

Each action is named twice in the list and no more: once in a line with its
title, in the tool that runs it, so the model knows what it does, and once in
that tool's `action` enum, so a client can check the call. `describe` takes
plain names: the list already shows them all, and an unknown or hidden name
comes back in its `unknown` list. A tool without a title is listed by its
name alone.

Why a read tool and a change tool per group: a group mixes reads and changes,
and `destructiveHint` is the only lever a client offers to ask the person
first. One tool per group would have to be marked destructive, and every read
would then ask for a confirmation. Describing goes through the read tool for
the same reason: reading a schema never asks the person anything. An action
sent to the wrong tool of its group, or to another group, is refused before
the gate is asked, with the name of the tool that runs it.

The groups are declared up front, not taken from the tools: `Discovery` asks
`owns()` with a name alone, before any tool is listed, so the mode must know
its names without the list. A tool whose `groupOf` is not a declared group
(an empty string, say) is listed as itself and called by its own name, which
is also how a host pins a ping. A group whose names would not make a valid
tool name, or that clashes with another group's change tool, is refused in
the constructor. Pick a `prefix` if a host tool could carry a group's name:
`Discovery` leaves such a host tool out rather than shadow it.

The mode does not search, so it never hands out a `search_id` and never
teaches the search; the contract test checks that too.

## Gateway

A single tool, `use_tools`, for clients that cap the number of tools very low
and for small models. Every call names an `operation`:

| `operation` | Arguments | What it does |
|---|---|---|
| `search` | `action`, `object`, `description`, `query` | the compact search, answer for answer: up to `maxDescribed` tools with their `inputSchema`, `unmatched` and `vocabulary`, a `search_id` when the host learns; with no field, every visible tool by name |
| `describe` | `names` | the tools a guide names, with their `inputSchema`; a hidden name comes back in `unknown` like a missing one |
| `run` | `name`, `arguments`, `search_id` | runs one host tool through `$runner->run()`; a failed run carries the tool's parameters |

```php
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;

$mode = new GatewayMode(
    prefix: 'acme_',             // acme_use_tools
    title: 'Outils',             // translated by you
    changesApart: false,         // true: changes listed as themselves, the gateway only reads
    groupOf: fn (Tool $t): string => $myGroups[$t->name] ?? 'other', // groups the list a bare search gives; every tool needs one
);
```

One tool carries one set of annotations, and `destructiveHint` is the only
lever a client offers to ask the person first. So the gateway is marked
destructive as soon as the caller may run a change through it, and marked as
a read when every tool it reaches only reads. A client that asks before every
destructive call will then ask before searches too: a host that would rather
avoid it sets `changesApart`. The changes are then listed as themselves, with
their own annotations, the gateway only reads, and a change sent through
`run` is refused before the gate is asked, with the name to call instead.

Search and describe share their code with the compact search
(`src/Mode/ToolFinder.php`), so a host that switches between the two modes
gets the same tools for the same request.

## Direct

Every visible tool, listed as itself and in the host's order, and no tool of
the mode's own: `owns()` is false for every name, so each call goes straight
to `$runner->run()`, the path every mode already gives a host tool called by
its name.

```php
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;

$mode = $settings->compact ? new CompactMode() : new DirectMode();
```

What the mode adds over a `tools/list` handler written without the library is
everything else `Discovery` does: a tool the gate hides is neither listed nor
run, `authorize()` is asked before each call, and the answers are shaped for
the client's revision. A host that offers the choice keeps one path for both
settings, and its cache and learning store wait, unused, for the day it
switches back. The mode does not search, so it hands out no `search_id`.

---

## What a mode never does

- show, find or describe a tool the gate hides;
- run anything without going through the runner;
- present a change as a read;
- decide access, keep state of its own across requests (the cache and the
  learning store are the host's, in its `SearchSetup`), or reach the network.
