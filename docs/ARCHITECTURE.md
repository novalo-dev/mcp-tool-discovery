# Architecture

The library answers two questions for an MCP server: what the client sees in
`tools/list`, and where a `tools/call` goes. It answers neither "who may do
this" nor "how to run this": the host does, through one interface.

---

## Layers

```
src/
  Discovery.php          entry point: one instance per request
  Tool/                  Tool, ToolSet: the host's definitions, unchanged
  Gate/                  ToolGate (the host), ToolRunner (the only door), CallResult
  Protocol/              Revision, Shaper, Json: the exact shape of each revision
  Mode/
    DiscoveryMode.php    the contract of a mode
    Compact/             the compact pack
  Search/                Analyzer, Index (BM25F), ToolSearch, SearchQuery, SearchOptions
    Cache/               IndexCache: in memory, in PHP files
    Learning/            LearningStore: in memory, in a JSON file
  Exception/
```

| Layer | Knows | Does not know |
|---|---|---|
| Tool | the MCP definition of a tool | who calls it |
| Gate | that a host decides; the search of the visible tools, and when it may learn | how the host decides |
| Protocol | each revision's fields | modes, search |
| Mode | how to present tools and route its own calls | access rules, transports |
| Search | text, words, scores | tools beyond their text, access |

Dependencies point inward: a mode uses Tool, Gate and Search; Protocol uses
Tool and Gate; nothing in the library depends on a mode except `Discovery`,
through the interface.

---

## The flow of a call

```
tools/call  ->  Discovery::callTool(name, arguments, tools, revision)
                  |
                  | visible = tools filtered by ToolGate::visible(),
                  |           minus host tools named like the mode's own
                  v
           mode->owns(name) ?
             yes -> mode->call(name, arguments, ToolRunner(visible, gate))
             no  -> ToolRunner::run(name, arguments)
                  |
                  v
           ToolRunner::run: not visible -> "Unknown tool" (same as missing)
                            gate->authorize() refuses -> the refusal, as an error
                            otherwise gate->call()
                  |
                  v
           Shaper::callResult(result, revision)  ->  the JSON-RPC result member
```

`tools/list` takes the same visible set, asks the mode for its list, and shapes
each tool for the revision.

## Invariants

These hold for every mode, and the contract tests check them:

1. **The visible set is computed before the mode runs.** A mode receives
   `ToolSet $visible` and a `ToolRunner` built on it; it never sees the full
   list or the gate.
2. **Hidden is the same as missing.** A hidden tool is never listed, found or
   described, and naming it gives the answer a nonexistent name gives.
3. **Nothing runs without `authorize()`.** `ToolRunner::run()` is the only path
   to `ToolGate::call()`.
4. **A refusal is an error result.** Tool-level failures travel inside the
   result with `isError`, as the specification asks, so the client reads them.
5. **A change is never presented as a read.** Only `readOnlyHint: true` makes a
   read; a mode tool that can run a change carries `destructiveHint`.
6. **The same input gives the same output.** Ties keep the host's order.

---

## Protocol revisions

`Revision` lists the revisions the library shapes answers for, and `Shaper`
cuts each answer to the fields that revision defines:

| Revision | Tool fields | Call result | List |
|---|---|---|---|
| `2024-11-05` | name, description, inputSchema | content, isError | tools |
| `2025-03-26` | + annotations (the title moves into them) | content, isError | tools |
| `2025-06-18` | + title, outputSchema, _meta | + structuredContent | tools |
| `2025-11-25` | + icons, execution | + structuredContent | tools |
| `2026-07-28` | name, title, description, inputSchema, outputSchema, annotations, icons, _meta | + resultType | + ttlMs, cacheScope, resultType |

The lists come from the official `schema.json` of each revision. The
conformance suite (`tests/Conformance/`) builds every kind of answer the
library gives - lists, searches, runs, refusals, malformed calls - for every
revision, and validates each against that revision's schema. The scores are
published as the README badges.

`Json` keeps JSON objects objects: PHP encodes an empty array as `[]`, and a
tool with no parameter would otherwise send `"properties": []`, which every
revision rejects.

The host keeps what belongs to the session: the version negotiation, the
JSON-RPC envelope, notifications, and the cache lifetime it passes as `ttlMs`.
The default is no caching and a `private` scope, since a list filtered by the
caller's rights is never public.

---

## Packs

Each mode lives in its own folder and depends only on the core. Composer's
autoloader loads a class the first time it is used, so a server that uses the
compact mode loads the compact pack and the search, and never the files of
another mode. If a pack ever needs a dependency of its own, it moves to a
package of its own, so the core keeps none.

## Performance

Without a cache, the search index is built per request, in memory: about 2 ms
for 50 tools and 25 ms for 500, query included. With an `IndexCache`, the build
is done once per set of visible tools: a search over 500 tools then takes
about 6 ms, and the `FileIndexCache` read through OPcache costs no more than
memory. The cache key is a hash of every text indexed, so there is nothing to
invalidate: a changed tool gives a new key.
