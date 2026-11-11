# Modes

A mode decides what a client sees in `tools/list` and how calls to the mode's
own tools reach the host's tools. Three are planned:

| Mode | Pack | State |
|---|---|---|
| Compact | `src/Mode/Compact/` | shipped |
| Grouped | `src/Mode/Grouped/` | open to contribution |
| Gateway | `src/Mode/Gateway/` | open to contribution |

---

## The contract

```php
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
  tool outside the visible set and asks the host's `authorize()` first.
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

Three tools: `search_tools`, `run_read_tool`, `run_write_tool`.

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
- `pinned` tools are listed as themselves, next to the three.
- `guideHeader()` gives the block to put at the top of a prompt that names
  tools, saying which runner each one goes through.

## Grouped (wanted)

One tool per group of host tools - `files`, `calendar`, `tickets` - so the
client sees the map of the server without searching, in tens of tools rather
than hundreds.

What it should do:

- take the groups from the host, as `groupOf: callable(Tool): string`, like the
  compact mode;
- list one tool per group that holds at least one visible tool, named after the
  group, whose description lists its actions in one line each;
- take `action` (the host tool's name) and `arguments`, and describe one
  action's `inputSchema` on request, since the group's own schema cannot hold
  them all;
- face the same question as the compact mode with changes: a group mixes reads
  and changes, so the change flag must still reach the client. One way is a
  read tool and a change tool per group; another may be better. Say which in the
  pull request, and why.

## Gateway (wanted)

A single tool that searches and runs, for clients that cap the number of tools
very low or for small models.

What it should do:

- list one tool, with an `action` of `search`, `describe` or `run`;
- search through `$runner->search()`, as the compact mode does, so the host's
  settings, cache and learning apply;
- keep changes visible to the client despite the single tool: the destructive
  flag of the one tool, or a two-step run where the first call returns what
  would change. Whatever the choice, the contract test
  `testAToolThatRunsAChangeIsMarkedAsOne` must pass.

---

## What a mode never does

- show, find or describe a tool the gate hides;
- run anything without going through the runner;
- present a change as a read;
- decide access, keep state of its own across requests (the cache and the
  learning store are the host's, in its `SearchSetup`), or reach the network.
