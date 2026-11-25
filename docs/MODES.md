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
| workspace | 50 | 16,147 | 2,871 | 1,839 | 13,982 | 1,161 |
| platform | 40 | 12,829 | 2,871 | 1,839 | - | 851 |
| people | 40 | 12,814 | 2,871 | 1,839 | - | 854 |
| library | 40 | 14,550 | 2,871 | 1,839 | - | 1,082 |
| school | 40 | 15,096 | 2,871 | 1,839 | - | 1,086 |
| hosting | 176 | 60,640 | 2,871 | 1,839 | - | 1,342 |
| city | 40 | 19,334 | 2,871 | 1,839 | - | 1,416 |
| newsroom | 40 | 20,067 | 2,871 | 1,839 | - | 1,682 |
| estate | 40 | 12,284 | 2,871 | 1,839 | - | 813 |
| depot | 40 | 17,134 | 2,871 | 1,839 | - | 1,353 |
| arena | 40 | 15,693 | 2,871 | 1,839 | - | 1,390 |
| academy | 40 | 12,433 | 2,871 | 1,839 | - | 765 |
| garage | 40 | 15,281 | 2,871 | 1,839 | - | 1,315 |
| hotel | 40 | 19,096 | 2,871 | 1,839 | - | 1,619 |
| clinic | 40 | 13,592 | 2,871 | 1,839 | - | 1,293 |
| water | 40 | 17,857 | 2,871 | 1,839 | - | 2,997 |
| mill | 40 | 19,842 | 2,871 | 1,839 | - | 1,635 |
| museum | 40 | 16,670 | 2,871 | 1,839 | - | 1,386 |
| bikes | 40 | 12,966 | 2,871 | 1,839 | - | 955 |
| ops | 150 | 50,720 | 2,871 | 1,839 | - | 1,015 |
| theatre | 40 | 15,392 | 2,871 | 1,839 | - | 1,247 |
| marina | 40 | 13,812 | 2,871 | 1,839 | - | 1,001 |
| post | 40 | 17,780 | 2,871 | 1,839 | - | 1,232 |
| sensors | 40 | 17,209 | 2,871 | 1,839 | - | 1,578 |
| ferry | 40 | 12,772 | 2,871 | 1,839 | - | 946 |
| kinder | 40 | 13,663 | 2,871 | 1,839 | - | 1,222 |
| fire | 40 | 13,864 | 2,871 | 1,839 | - | 881 |
| drive | 40 | 15,424 | 2,871 | 1,839 | - | 1,175 |
| chess | 40 | 16,197 | 2,871 | 1,839 | - | 1,267 |
| cellar | 40 | 19,520 | 2,871 | 1,839 | - | 1,984 |
| coffee | 40 | 14,401 | 2,871 | 1,839 | - | 1,042 |
| garden | 40 | 12,309 | 2,871 | 1,839 | - | 810 |
| desk | 150 | 49,454 | 2,871 | 1,839 | - | 1,231 |

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
  server. On the workspace its 8 tools take 7,154 bytes where the 28
  actions they stand for would take 9,299: on short tools it saves almost a
  quarter, more as the schemas grow.
- **Direct** lists every tool as itself, the first column. It suits a server
  with a few tools, where a search would cost more than it saves, and the
  host that lets its users turn the other modes off.

To see each one on a client of your own before choosing, the example server
over stdio takes the mode by name, on the same four tools:
`MCP_NOTES_MODE=grouped php docs/examples/stdio-server.php`, or `gateway`,
`direct`, or nothing for compact ([HOSTS.md](HOSTS.md#a-server-over-stdio)).

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
  own: the host's settings, cache, learning store and log come with it. A
  mode hands out a `search_id` when `$runner->followsSearches()` is true,
  and passes it back to `$runner->run()`, which learns only from a visible,
  authorized run that succeeded, and tells the host's log of the run.
- Build your tool definitions with `Tool::fromArray()`, so they are validated,
  and let `Shaper` adapt them to each revision.

A mode that does not list every tool as itself can also implement
`Mode\GuideHeader`, apart from the contract so a mode written elsewhere keeps
working without it: `guideHeader(iterable $names, ToolSet $visible)` returns
the block to put at the top of a prompt that names tools, or `""`. See
[Guides](#guides).

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
- `pinned` tools are listed as themselves, next to the three; a name given
  twice is listed once.
- A `prefix` that makes no valid tool name (a space, or more than 128
  characters with the name) is refused in the constructor, as the grouped
  mode refuses a group: the host learns of it at boot, not on the first
  `tools/list`. `run_write_batch` is only checked when it is on.
- `titles` takes the keys `search`, `read`, `write` and `batch`. Any other
  key, `run_read_tool` say, is refused in the constructor: it would leave
  the English title on every screen without a word.
- The guide header (see [Guides](#guides)) says which runner each tool a
  prompt names goes through, and which of them are pinned and called
  directly.

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
the constructor, and so are `groups` given as a plain list of names (its
keys would be 0, 1, 2, so no tool would fall in a group) and a
`changeTitles` key that is not a group. Pick a `prefix` if a host tool could carry a group's name:
`Discovery` throws `InvalidTool` on the first request that meets such a host
tool, since it could never be called.

The mode does not search, so it never hands out a `search_id` and never
teaches the search; the contract test checks that too.

## Gateway

A single tool, `use_tools`, for clients that cap the number of tools very low
and for small models. Every call names an `operation`:

| `operation` | Arguments | What it does |
|---|---|---|
| `search` | `action`, `object`, `description`, `query` | the compact search, answer for answer: up to `maxDescribed` tools with their `inputSchema`, `unmatched` and `vocabulary`, a `search_id` when the host learns; with no field, every visible tool by name; each tool that changes something carries `"changes": true` |
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

Without `changesApart`, every tool a search finds names the gateway in
`run_with`, so nothing there tells a read from a change, and the model could
not know which calls to confirm with the person, which the gateway's
description asks of it. So each tool that changes something comes with
`"changes": true`, in a search, a description and the bare list alike. Reads
carry nothing, so a server that only reads pays nothing for it; with
`changesApart`, `run_with` already names each change, and the mark is left
out.

A `prefix` that makes no valid tool name is refused in the constructor, as
in the other modes.

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

## Guides

An MCP prompt that names tools reads wrong in any mode but Direct: the tools
it names are not in the client's list as themselves, and the client looks for
tools it cannot see. `$discovery->guideHeader($toolNames, $tools)` returns
the block to put at the top of the prompt text, written for the current mode:

| Mode | What the header says |
|---|---|
| Compact | read the parameters with `search_tools` (names), run each through `run_read_tool` or `run_write_tool`; pinned tools are called directly |
| Grouped | each tool is an action of its group: read it with `describe` on the group's tool, run it through `<group>` or `<group>_change`; tools outside every group are called directly |
| Gateway | read with `operation` `describe`, run with `operation` `run`, all through `use_tools`; with `changesApart`, the changes are called directly |
| Direct | nothing: `""` |

```php
$text = $discovery->guideHeader(['acme_get_file', 'acme_set_ticket_status'], $tools) . $myPromptText;
```

It is `""` when every tool the prompt names is in the list as itself, so a
host can call it for every prompt. Pass the host's whole `ToolSet`, as for
`listTools()`: `Discovery` keeps only the tools the caller may see, and a
hidden tool the prompt names is left out of the header. A mode of your own
gets a header by implementing `Mode\GuideHeader`; one that does not gets
`""`.

## Instructions

Clients put the `instructions` of the `initialize` answer (of
`server/discover` from `2026-07-28`) in the model's system prompt, read
before any tool. In every mode but Direct the list is not the whole server,
and a model shown three tools for three hundred concludes the one it needs
is missing. `$discovery->instructions()` returns one or two sentences that
say how the current mode reaches the others, to put after the host's own:

| Mode | What the instructions say |
|---|---|
| Compact | most tools are not in the list: find them with `search_tools`, run each with `run_read_tool` or `run_write_tool` (and `run_write_batch` when it is on); a tool in the list is called directly |
| Grouped | each tool of a group is an action of the group's tool: read it with `describe`, run a read with `action` and `arguments`, a change on `<group>_change`; any other tool is called directly |
| Gateway | every tool goes through `use_tools`, with `operation` `search`, `describe` and `run`; with `changesApart`, only the reads, and the changes are called directly |
| Direct | nothing: `""` |

```php
$result['instructions'] = trim('Files and tickets of the team. ' . $discovery->instructions());
```

They are the same for every caller: they name the mode's own tools, never
one of the host's, not even a pinned one, since the gate may hide it from
some. So they can go in a `server/discover` answer cached with
`cacheScope` `public`. A mode of your own gets them by implementing
`Mode\Instructions`; one that does not gets `""`.

## What a mode never does

- show, find or describe a tool the gate hides;
- run anything without going through the runner;
- present a change as a read;
- decide access, keep state of its own across requests (the cache and the
  learning store are the host's, in its `SearchSetup`), or reach the network.
