# Changelog

All notable changes to this library are documented in this file. The format is
based on Keep a Changelog, and the library follows Semantic Versioning.

What is merged on `develop` but not released waits under **Unreleased**, and
becomes the next version's section when it is published.

## Unreleased

### Added

- **The server on logiscape/mcp-sdk-php over HTTP.**
  `docs/examples/logiscape/http-server.php` serves the notes tools through
  the SDK's `HttpServerRunner`, one process per request as PHP-FPM runs it,
  with the gate and the `Discovery` built for the caller of each request.
  Three traps of the SDK are covered: the runner keeps its sessions in
  memory unless given a store, so the example gives it a
  `FileSessionStore`; it builds its session itself, so the instructions are
  set on the JSON answers to `initialize` and `server/discover` by
  `instruct()`, decoded as objects so that the empty objects of the answer
  stay `{}`; and it binds a session to no caller without its OAuth, which
  is harmless here because the gate is built from each request's token,
  never from the session. The handlers both servers register now live once
  in `serve()`. The `logiscape` test suite calls it over HTTP, the
  stateless revision included.

- **A wine cellar written in Georgian on the search benchmark.** 40 tools
  in English snake_case, with Georgian titles and descriptions, most of
  them neighbours on one object (fill a qvevri, seal it, open it, wash it;
  plan a bottling, cancel it, record it). Its 100 requests were written
  before any of them was run, with the verbs as people type them and 13 of
  them in Mtavruli, the Georgian capitals. Nothing was tuned on it: 42 of
  its 50 test requests come first in fields, 49 in the top 3, 33 as one
  sentence, and learning loses none. The benchmark now holds 3345 requests
  over thirty servers; its floors move to 1287 and 1505 in fields and 1410
  asked again, and the laravel/mcp line to 534 and 802 as one sentence and
  924 and 1199 with the fields joined.

- **A server on logiscape/mcp-sdk-php in the host examples.**
  `docs/examples/logiscape/` serves the notes tools on the other MCP SDK for
  PHP many servers run on, pinned at 2.0.2, over stdio. The library answers
  the `tools/list` and `tools/call` handlers of the SDK's `Server`; the
  example leaves out its `McpServer` wrapper, which would announce prompts
  and resources the server does not have, and adds the instructions to
  `initialize` and `server/discover` through a subclass of the SDK's
  session, since the SDK has no setting for them. A CI job installs the SDK
  and runs the `logiscape` test suite, as part of **CI passed**: the SDK is
  past 1.0 and pinned. docs/HOSTS.md says what each part does and why.

- **A chess club written in Armenian on the search benchmark.** 40 tools
  in English snake_case, with Armenian titles and descriptions, most of
  them neighbours on one object (pair a round, swap two pairings, publish
  them; record a result, correct it, record a forfeit). Its 100 requests
  were written before any of them was run, typed with the question and
  emphasis marks set inside the word (`փոխե՞լ`), with `եվ` for `և`, and
  some starting with a capital. Nothing was tuned on it: 44 of its 50 test
  requests come first in fields, all 50 in the top 3, 39 as one sentence,
  and learning loses none. The benchmark now holds 3245 requests over
  twenty-nine servers; its floors move to 1245 and 1456 in fields and 1364
  asked again, and the laravel/mcp line to 517 and 774 as one sentence and
  904 and 1166 with the fields joined.

- **A driving school written in Persian on the search benchmark.** 40
  tools in English snake_case, with Persian titles and descriptions written
  with the zero-width non-joiner inside words, most of them neighbours on
  one object (book, move and cancel a lesson; book, cancel and record a
  test). Its 100 requests were written before any of them was run, typed
  with the joiner, with a space in its place, glued, and a few on an Arabic
  keyboard (`ي`, `ك` for `ی`, `ک`). Nothing was tuned on it: 38 of its 50
  test requests come first in fields, 33 as one sentence, and learning
  loses none. The benchmark now holds 3145 requests over twenty-eight
  servers; its floors move to 1201 and 1406 in fields and 1317 asked again,
  and the laravel/mcp line to 486 and 736 as one sentence and 870 and 1123
  with the fields joined.

- **A fire station written in Spanish on the search benchmark.** 40 tools
  named in Spanish snake_case, most of them neighbours on one object (take
  a vehicle out of service and put it back, send a crew and call it back,
  schedule and cancel an inspection). Its 100 requests were written before
  any of them was run, most typed as Spanish is in a hurry: without
  accents, and with the pronoun joined to the verb (`quitala`). Nothing was
  tuned on it: 43 of its 50 test requests come first in fields, 37 as one
  sentence. The benchmark now holds 3045 requests over twenty-seven
  servers; its floors move to 1163 and 1360 in fields and 1273 asked
  again, and the laravel/mcp line to 456 and 699 as one sentence and 833
  and 1081 with the fields joined.

- **Russian requests typed without `ё` on the search benchmark.** Twelve
  more on each half of the hotel, written before any of them was run, typed
  with `е` where the tools write `ё` (`счет` for `счёт`), as Russian is most
  often typed. The benchmark now holds 2945 requests; its floors move to
  1120 and 1314 in fields and 1229 asked again, and the laravel/mcp line to
  436 and 672 as one sentence and 806 and 1043 with the fields joined.

- **Greek requests in capitals and without accents on the search
  benchmark.** Twelve more on the town hall and twelve on the museum, written
  before any of them was run, typed as Greek is typed in capitals or in
  haste: no accent, and a plain `σ` where the tools end a word with `ς`.
  The benchmark now holds 2921 requests; its floors move to 1109 and 1303
  in fields and 1217 asked again, and the laravel/mcp line to 431 and 663
  as one sentence and 800 and 1034 with the fields joined.

- **Server instructions from the mode, with `Discovery::instructions()`.**
  Clients put the `instructions` of the `initialize` answer (of
  `server/discover` from 2026-07-28) in the model's system prompt, before
  any tool. In Compact, Grouped and Gateway the list is not the whole
  server, and a model that is not told so concludes the tool it needs is
  missing; every host had to write that sentence itself and keep it in step
  with its prefix and its options. It now gets one or two sentences for the
  current mode, to put after its own words, and `""` in Direct. They name
  the mode's own tools only, never one of the host's, so they are the same
  for every caller and can go in a discover answer cached as `public`. A
  mode of your own gets them by implementing the new `Mode\Instructions`
  interface, kept apart from `DiscoveryMode` so existing modes are not
  broken. Every example server sends them: those on the official PHP SDK
  through `setInstructions()`, and the laravel/mcp one from the mode its
  methods list with, in place of a sentence written by hand.

- **A tool set that leaves out a bad tool instead of failing.**
  `ToolSet::of($tools, $refused)` takes a callback: a definition the
  library cannot work with, or a later tool under a name already taken, is
  left out and handed to it with its `InvalidTool`, and every other tool is
  kept. A host that gathers tools from plugins no longer has to build each
  `Tool` itself and catch the exception one by one. Without the callback,
  the first bad tool still throws, as before.

- **A kindergarten written in Vietnamese on the search benchmark.** 40
  tools named in snake_case with titles and descriptions in Vietnamese,
  which writes each syllable apart and is often typed without its tone
  marks and without `đ`. Most are neighbours on one object (record and
  cancel a notice of absence, add and remove a person allowed to pick a
  child up, issue and void a receipt). Its 100 requests were written before
  any of them was run; learning lost it a first place until a learned lift
  was scaled by the share of the learned search the request holds, and now
  loses none. The benchmark now holds 2897 requests over twenty-six
  servers; its floors move to 1089 and 1280 in fields and 1198 asked again,
  and the laravel/mcp line to 431 as one sentence and 800 with the fields
  joined.

- **A ferry line written in Turkish on the search benchmark.** 40 tools
  named in snake_case with titles and descriptions in Turkish, whose words
  take one suffix after another and are often typed without `ş` or `ı`,
  and whose titles start with the capital `İ` that each letter lowered on
  its own now meets. Most are neighbours on one object (sell, cancel,
  refund and move a ticket; block and unblock a card). Its 100 requests
  were written before any of them was run; learning lost it a first place
  until a learned lift was scaled by the share of pairs that reached the
  tool, and now loses none. The benchmark now holds 2797 requests over
  twenty-five servers; its floors move to 1071 and 1249 in fields and 1171
  asked again, and the laravel/mcp line to 426 as one sentence and 791
  with the fields joined.

- **A guide header in every mode, from `Discovery::guideHeader()`.** A
  prompt that names tools now gets its header in Grouped and Gateway too:
  in Grouped, each tool is named with the group tool that runs it and read
  with `describe`; in Gateway, it is read with `operation` `describe` and
  run with `operation` `run`, and with `changesApart` the changes are
  called directly. Before, only `CompactMode::guideHeader()` existed, and a
  host that switched a client to another mode lost the header of its
  guides. Call it on `Discovery` with the host's whole `ToolSet`: it keeps
  only the tools the caller may see, so a hidden tool a prompt names is
  never written in its header, and it gives `""` in Direct or in a mode
  without a header. A mode of your own gets one by implementing the new
  `Mode\GuideHeader` interface, kept apart from `DiscoveryMode` so existing
  modes are not broken.

### Changed

- **`UnsupportedRevision` no longer repeats the client's string as sent.**
  The revision comes from the client, and a host that writes the message to
  its log wrote whatever the client put there, line breaks and terminal
  sequences included, at any length. The message now names it as unknown
  tool names are named: cut to 128 characters, anything but letters,
  digits, `_`, `.` and `-` shown as `?`. A real revision such as
  `2099-01-01` reads as before.

- **The quick start answers `initialize` with the mode's instructions.**
  It only said which `protocolVersion` to answer, so a host that copied it
  and nothing more sent no `instructions`, and the model read the three
  tools of Compact as the whole server. Its third block now builds the
  whole `initialize` result, with `$discovery->instructions()` after the
  host's own words, and says where they go in `2026-07-28`; the test that
  runs the quick start checks them.

- **A Russian word typed with `е` for `ё` finds the tool word it
  spells.** As for Greek, a Cyrillic letter outside а to я (`ё`, and the
  `і`, `ї`, `є` of Ukrainian) may be met by any letter of а to я and the
  other way round, so `счет` now reaches `счёт`. Still no table and no
  extension. On the test half, 1314 to 1315 in the top 3 in fields (the
  floor moves with it), 1371 to 1372 in the top 3 asked again, and 831 to
  830 first as one sentence, where two Ukrainian tools now both write the
  word a request spells. A cached search over a server written in Cyrillic
  takes about 0.25 ms against 0.15 ms.

- **A Greek word typed without its accents finds the tool word it
  spells.** As for a Latin letter outside ASCII, a Greek letter outside α
  to ω (a tonos, a dialytika, polytonic marks) may be met by any letter of
  α to ω, and a sigma by a final sigma: a request typed in capitals
  (`ΚΡΑΤΗΣΕΙΣ`, which lowers to `κρατησεισ`) now reaches `κρατήσεις`. Still
  no table and no extension. On the test half, 815 to 820 first as one
  sentence and 1217 to 1218 asked again, the requests in fields as before;
  the floor asked again moves to 1218. A cached search over a server
  written in Greek takes about 0.25 ms against 0.14 ms.

- **A word typed without its marks finds the tool word it spells.** A
  request typed as many people type Vietnamese or Polish, on a phone above
  all ("hoc phi" for "học phí", "doladuj" for "doładuj"), met the tool
  only by trigrams, which the marks of a short word mostly change. A word
  no tool uses now also counts as each tool word of its length that has
  the same letters, except where one side has a Latin letter outside ASCII
  and the other any letter of ASCII, as long as the letters that match
  outright are at least as many. No table of letters and no extension: the
  Unicode script of a character, read by PCRE, is all it takes, so a
  Greek or Cyrillic word is never matched by a word of ASCII. On the test half, 1089 to
  1099 first in fields, 798 to 807 as one sentence, 1198 to 1206 asked
  again; no server loses a request. The tool words a word may spell are
  first sifted by one pass of PCRE, letter by letter, so a cached search
  over a server written with marks takes about as long as before (0.15 ms
  over 40 tools), and one in English no longer.

- **What was learned for a neighbour lifts it less.** A learned lift is now
  also multiplied by the square of the share of a learned search's words
  that the new request holds, so "view the instruments free to lend", run
  for the list, no longer puts the list before the loan for "lend an
  instrument". Each run keeps its whole search in the store, under a key
  that starts with a tab; a store written before keeps working, its tools
  lifted as before until new runs come. On the benchmark, learned from the
  other half 1072 to 1074 first, asked again 1171 to 1175 (floor moved),
  and no server loses a request to learning. A search that learns reads
  every key of the store once more: about 0.55 ms against 0.35 ms over 176
  tools.

- **Plain words cost little over an index of words mostly of marks.** A word
  of a request is no longer held against the tool words whose number of
  letters with marks differs from its own by more than half their length,
  which can never spell it. Over an index of words mostly of marks, a plain
  word cost as much as reading every word of its length; it now costs as
  little as any other. The ranking of every request of the benchmark is
  unchanged.

### Fixed

- **docs/MODES.md gives the bytes of one compact search as measured.**
  The changes to the search since the table was written had moved the
  answer on seven servers, the kindergarten from 1,438 to 1,222 bytes, and
  nothing checked it. `ListCostTest` now compares the whole table, and the
  README sentence on the largest server, with what the benchmark measures,
  and its failure shows the table to paste.

- **docs/MODES.md gives the bytes of the group tools as measured.** Its
  sentence on the grouped mode (8 tools of 7,154 bytes for 28 actions of
  9,299 on the workspace) was copied by hand, outside the table the test
  holds. `ListCostTest` now compares it with what `ListCost::groups()`
  measures, and `composer benchmark` prints it under the table.

### Security

- A word of a request counts as at most 32 tool words it spells but for its
  marks, as for the words alike it, so the cost of a search stays small
  however many words of a large index share most of their letters. The
  ranking of every request of the benchmark is unchanged.

- A word of a request whose letters with marks outnumber its plain letters
  is no longer held against the plain words of the index, which can never
  spell it, so its search costs as little as any other on a large index.
  The ranking of every request of the benchmark is unchanged.

## 0.7.0 - 2026-11-23

### Added

- **A fleet of field sensors generated from OpenAPI on the search
  benchmark.** 40 tools exposed as OpenAPI-to-MCP generators write them:
  the method and the path for a name (`post_devices_device_id_reboot`), no
  title, the summary, longer text and route as description, and the same
  `page` and `per_page` on every list. Most are neighbours on one object.
  Its 100 requests were written before any of them was run and split as
  the airline's. The benchmark now holds 2697 requests over twenty-four
  servers; its floors move to 1037 and 1207 in fields and 1134 asked
  again, and the laravel/mcp line to 408 as one sentence and 764 with the
  fields joined.

- **Every mode on the example servers.** `MCP_NOTES_MODE` now takes
  `grouped` and `gateway` beside `direct`, on the stdio, ReactPHP and SDK
  servers alike, through one `mode()` in `docs/examples/notes.php`, so the
  two modes that are not the default can be tried on a real client with
  the same tools and the same gate. The tests run both over stdio as a
  client would: a role granted in grouped mode adds the deletion to the
  change tool and the client is told, a change sent to the read tool is
  refused with the name of the tool that runs it, and in gateway mode the
  one tool stays as it was while a search marks the deletion as a change.
- **A post office written in Lao on the search benchmark.** 40 tools named
  in snake_case with titles and descriptions in Lao, written as Thai is,
  with no space between words, most of them neighbours on one object (send,
  track, hold, return and redirect a parcel; send, cancel and pay out a
  money transfer; rent, renew and close a post office box). Its 100 requests
  were written before any of them was run and split as the airline's. The
  benchmark now holds 2597 requests over twenty-three servers; its floors
  move to 998 and 1159 in fields and 1088 asked again, and the laravel/mcp
  line to 385 as one sentence and 726 with the fields joined.
- **A marina written in Hebrew on the search benchmark.** The first
  language that glues its particles to the start of a word ("במרינה", in
  the marina), and the first names in Train-Case (`Berth-Reserve`): 40
  tools, most of them neighbours on one object (reserve, release, extend
  and move a berth; close and reopen the harbour). Its 100 requests were
  written before any of them was run and split as the airline's; both
  halves score alike in fields (40 of 50 first on each). The benchmark now
  holds 2497 requests over twenty-two servers; its floors move to 963 and
  1115 in fields and 1047 asked again, and the laravel/mcp line to 374 as
  one sentence and 696 with the fields joined.
- **A log of what clients search.** `SearchSetup` takes a `SearchLog`, an
  interface of two methods: `searched()` receives each search a mode runs,
  with its answer (the tools found, the words no tool uses), and `ran()`
  each run of a visible, authorized tool, whether it succeeded, and the
  `search_id` the client gave back with it. With a log, the compact and
  gateway modes hand out a `search_id` even when nothing is learned, so a
  host can tie a run to its search and read its misses. A mode written
  outside the library asks `ToolRunner::followsSearches()` instead of
  `learns()` to know whether to hand one out. A `search_id` a search could
  not have written reaches the log as null. The library writes nothing
  itself; docs/SEARCH.md has an example that writes JSON lines.
- **A search log in the stdio example.** `docs/examples/stdio-server.php`
  keeps one line of JSON per search and per run in the file
  `MCP_NOTES_SEARCH_LOG` names, and nothing when it names none:
  `NotesSearchLog`, in `docs/examples/notes.php`, is a log a host can copy.
  A line it cannot write is lost without failing the client's call. Its
  test holds it to what the log promises: the run carries the id of its
  search, a tool the gate hides is never written, and an id no search
  wrote reaches the log as null.
- **A report of the search log.** `docs/examples/search-log-report.php`
  reads the log the stdio example writes and prints, most frequent first,
  the words no tool uses with the tools that ran after them (the host's
  synonyms to write), the runs of a tool the search did not put first, with
  its rank, and the searches that found nothing. A failed run is counted
  but ranks nothing; a line cut short is counted and skipped. It needs
  nothing but PHP, and its test reads the log the stdio server wrote in the
  same test.
- **An example of a server written with laravel/mcp.** Laravel's official
  MCP package lets a server replace any of its methods with `addMethod()`:
  `docs/examples/laravel-mcp` replaces `tools/list` and `tools/call` with
  two methods that hand them to the library, and keeps laravel/mcp's tools,
  transports and other methods as they are. The library is given only the
  tools `ServerContext::tools()` keeps for the request, so a tool hidden by
  its `shouldRegister()` can be neither found nor run through a runner; a
  tool runs through laravel/mcp's `ToolInvoker` with its own request, so
  its validation and error results are unchanged. docs/HOSTS.md has the
  section, and a CI job installs laravel/framework 13.43.0 and laravel/mcp
  1.2.1 there and runs its tests, as part of CI passed.
- **A city theatre written in Ukrainian on the search benchmark.** The
  second server in Cyrillic, after the Russian hotel: 40 tools, mostly
  neighbours on one object (move and cancel a performance; book a seat,
  release a booking, refund and exchange a ticket), and words that change
  their endings or carry an apostrophe inside. Its 100 requests were
  written before any of them was run and split as the airline's; both
  halves score alike (40 of 49 and 40 of 51 first in fields). The benchmark
  now holds 2397 requests over twenty-one servers; its floors move to 923
  and 1069 in fields and 1005 asked again.
- **The benchmark measures the search a PHP host may already have.**
  laravel/mcp v1.2.1, Laravel's official MCP package, ships a substring
  score over the name, the description and the input schema; it is written
  again in `tests/Fixtures/SubstringSearch.php` and run on the same test
  half. As one sentence it finds the right tool first 353 times out of
  1200, against 665 for this library; given the words of the fields joined,
  662, against 923 in fields. `composer benchmark` prints both, the README
  and docs/SEARCH.md give them, and `BenchmarkTest` fails if the library
  stops beating it on either line. It lowers text letter by letter, as
  the Analyzer does, so its score is the same on every PHP version.

### Changed

- **Learning barely moves a neighbour.** The lift of a learned tool is
  now also scaled by the share of the request's pairs of words that
  reached it. Run once, "add a site address" lifted the tool that creates
  a site for "change a site address", which shares one pair of three; it
  now lifts it a ninth as much, while the same request asked again keeps
  its whole lift. Learned from the train half, the test half no longer
  loses first places to learning: 1038 against 1037 in fields, where the
  plain mean found 1035, and 1134 asked again either way. Stores keep
  their format.
- **The guide header names the pinned tools.** A prompt that names a pinned
  tool beside tools behind the runners used to have its pinned tool left
  out of `CompactMode::guideHeader()`, under a header that says the
  prompt's tools are not in the list, so a client could search for a tool
  it already holds. The header now ends with "directly, as they are in
  your list:" and those names, and its first line says so. A host that
  added that line itself can drop it.

- **Lao is searched by pairs of what is seen, as Thai and Burmese are.** A
  run of Lao was one token that met tools only by its trigrams. It is now
  cut into overlapping pairs of grapheme clusters, the run kept beside
  them. On the test half of the new post office, 35 of 48 requests find the
  right tool first in fields against 33, 44 are in the top 3 against 43,
  fewer tools are returned (2.40 against 2.48), learning loses nothing, and
  41 come first asked again against 37; as one sentence, one request leaves
  the first place and two enter the top 3. Chosen on its train half: 47 of
  52 first against 42. No other server moves by a single request. The index
  format changes, so a cached index is rebuilt once.

### Security

- A search scores at most 32 words of a request that no tool uses, and for
  each at most the 32 indexed words most alike it, so the cost of a search
  stays small however many words of a large index look alike. The ranking
  of every request of the benchmark is unchanged.
- The search log report prints the words of a request as text only: a
  control or direction character shows as `?`.

## 0.6.0 - 2026-11-22

### Added

- **An airline operations centre on the search benchmark, with 150 tools.**
  Named by a dotted object and a hyphenated action
  (`flight.change-aircraft`), in English, and mostly neighbours on one
  object (delay, cancel, reinstate and divert a flight; assign, unassign and
  swap a crew member; block and unblock a gate or a seat), as large servers
  are. Its 300 requests were written before any of them was run, and split
  so that each half holds the first request of half the tools: both halves
  score alike (111 and 117 first in fields). The benchmark now holds 2297
  requests over twenty servers; its floors move to 879 and 1023 in fields
  and 961 asked again.

- **The SDK HTTP example tells a client of `2026-07-28` that its list
  changed.** A `subscriptions/listen` stream opened with `toolsListChanged`
  is sent `notifications/tools/list_changed` once when the list its caller
  may see moves. The host publishes on the SDK's shared bus that the tools
  changed (`docs/examples/sdk/grant.php` does, for a right granted or taken
  back); `CallerNotifications` reads that bus for one caller and passes the
  change on only when `listFingerprint()` differs, so a reader is never told
  of an admin's tools and compact mode tells no one. The bus is a
  `Psr6NotificationBus` in files, shared by PHP-FPM workers, which adds
  `symfony/cache` to the example's `composer.json`. `toolsListChanged` is
  declared only to clients of that revision. `grantedRole()` in
  `docs/examples/http.php` now reads the grants file for the ReactPHP
  example and this one.

- **A Polish city bike rental on the search benchmark.** 40 tools named
  in snake_case with titles and descriptions in Polish, many of them
  neighbours (lock and unlock a bike, block and unblock an account, close
  and reopen a station, issue and waive a penalty), and 100 requests written
  before any of them was run, alternating with and without the Polish
  letters so that both halves hold both. It is the first server where a
  request drops letters no decomposition gives a base to ("zglos" for
  "zgłoś"). The benchmark now holds 1997 requests over nineteen servers; its
  floors move to 762 and 897 in fields and 839 asked again.

- **The ReactPHP example sends `notifications/tools/list_changed` over
  HTTP.** `initialize` opens a session with an `Mcp-Session-Id`, bound to
  the token that opened it, and declares `listChanged`; a `GET` naming the
  session opens its `text/event-stream`. Each `tools/list` keeps the
  `listFingerprint()` of what the client holds, and a timer of the event
  loop sends the notification once when the rights of the caller change it.
  `MCP_NOTES_MODE=direct` and `MCP_NOTES_GRANTS` show it; a request with no
  session is answered as before. `docs/examples/http.php` gains
  `originAllowed()` and `tools()`, so the stream checks Origin the way every
  other request does.

- **`Discovery::listFingerprint()`, to know when to send
  `notifications/tools/list_changed`.** A short string that changes when,
  and only when, the list a caller would be given changes, in the client's
  revision. It covers every page at once and leaves out `nextCursor`,
  `ttlMs` and `cacheScope`, so a host that pages its list no longer
  compares only the page it last answered, and a host that keeps it in a
  session keeps a hash instead of the whole answer. The stdio example and
  docs/HOSTS.md use it.

- **The stdio example sends `notifications/tools/list_changed`.** It
  declares `listChanged` at `initialize`, keeps the `tools/list` answer the
  client last received, and sends the notification once when the list built
  for the next message differs. With `MCP_NOTES_ROLE_FILE` it reads the role
  again at each message, as a server reads its user store at each request,
  and `MCP_NOTES_MODE=direct` lists every tool as itself. Its tests grant and
  take back a role while a client is connected: in direct mode the client is
  told once each time, in compact mode never, and its next search finds the
  tool the role now shows.

- **A Greek archaeological museum on the search benchmark.** 40 tools named
  in snake_case with titles and descriptions in Greek, many of them
  neighbours (register, correct, move and strike off a find; request,
  approve and return a loan; book, cancel and move a guided tour), and 100
  requests written before any of them was run, about a quarter without
  accents and one in eight in capitals. The benchmark now holds 1897
  requests over eighteen servers; its floors move to 723 and 851 in fields
  and 795 asked again.

- **The examples on the official PHP SDK page `tools/list`.** `ToolsHandler`
  and `RegistryToolsHandler` hand the `cursor` of the SDK's `ListToolsRequest`
  to `listPage()` and answer a cursor the list did not give with the SDK's
  invalid params error, -32602, instead of an internal one. Both take a
  `pageSize`, 50 by default like the SDK's `setPaginationLimit()`, so a host
  that copies them pages its tools as the SDK pages its other lists.

- **`Discovery::listPage()`, for a host that pages `tools/list`.** It takes
  the `cursor` of the request and a page size, and answers one page with a
  `nextCursor` while tools remain; the pages put together are the answer of
  `listTools()`. The cursor names the last tool of its page, so a tool added
  or removed elsewhere between two pages neither skips nor repeats one. A
  cursor the list did not give, or whose tool has gone or is hidden from the
  caller, throws the new `InvalidCursor`, to be answered with error -32602;
  its message is the same in every case. `listTools()` is unchanged. See
  "A list in pages" in docs/HOSTS.md.

- **What to do when the tools change, in docs/HOSTS.md.** A table says, for
  each mode, which changes to the host's tools change the `tools/list` a
  client holds and which only reach it through a search: in compact mode only
  a pinned tool moves the list. With it, how to send
  `notifications/tools/list_changed` with a session, how long a `ttlMs` may be
  in the stateless revision, and what becomes of the index cache and the
  learning store; `FileIndexCache` keeps the files of past tool sets, and its
  directory may be emptied when the server is deployed. A test holds the table
  and the claim that a search and a run follow the new tools at once, with a
  shared cache and learning store.

- **A Burmese rice mill on the search benchmark.** 40 tools named in
  snake_case with titles and descriptions in Burmese, many of them neighbours
  (receive, correct and cancel a delivery of paddy; pay a farmer and void the
  payment; record and cancel a sale; grant and repay an advance; book and
  cancel a truck), and 100 requests written before any of them was run, most
  of them with no space at all. The benchmark now holds 1797 requests over
  seventeen servers; its floors move to 685 and 803 in fields and 754 asked
  again.

- **A Khmer water supply office on the search benchmark.** 40 tools named in
  snake_case with titles and descriptions in Khmer, a script written without
  spaces between words and with stacked consonants, many of them neighbours
  (cut and reconnect the water; issue, void and correct a bill; schedule,
  cancel and announce an outage), and 100 requests written before any of them
  was run. Nothing in the search changed for it: 32 of its 50 test requests
  find the right tool first in fields, 32 as one sentence, and learning loses
  nothing. Cutting Khmer into pairs of clusters, as Thai is, was measured on
  its train half and not kept: no more tools first, more tools returned. The
  benchmark now holds 1697 requests over sixteen servers; its floors move to
  649 and 758 in fields and 710 asked again.

- **The SDK example for tools already in its registry, in
  `docs/examples/sdk/registry.php`.** A server written for the official PHP
  SDK keeps its tools as it registered them, with `addTool()` or attribute
  discovery. The library reads their definitions from the SDK's registry and
  runs each one through the SDK's own `CallToolHandler`, so the schema the SDK
  derived is still checked, results are formatted as before and a
  `ToolCallException` is still the tool's error; arguments the schema refuses
  come back as an error result the assistant can correct. A tool the gate
  hides stays unknown even called by its own name. docs/HOSTS.md says what
  changes from the first SDK example, and the `sdk` test suite runs both.

- **The SDK example for tools found by their `#[McpTool]` attribute, in
  `docs/examples/sdk/attributes.php`.** The note tools are the methods of
  one class, found by the SDK's discovery, with their descriptions taken
  from the doc comments. They reach the same registry and run through the
  same handler and gate as `registry.php`, now shared in
  `registry-bridge.php`, and the `sdk` test suite holds both servers to the
  same tests. docs/HOSTS.md lists the three things the attribute server
  needs: `symfony/finder`, which the SDK only suggests; the class autoloaded
  by Composer; and the SDK's container given to the `ReferenceHandler` too,
  without which the class of the tools is built with no argument and every
  call fails.

- **The SDK example over its HTTP transport, in
  `docs/examples/sdk/http-server.php`.** The same handler as the stdio
  server, behind the SDK's Streamable HTTP transport, one process per
  request as PHP-FPM runs it. The caller is read from the bearer token
  before the SDK, and the gate and `Discovery` are built for it on each
  request; the SDK keeps the session in files, and the caller is set as the
  request's `PRINCIPAL_ATTRIBUTE`, so another token sent with the session's
  id is answered 404 and one caller's gate never runs on another's session.
  The index is cached on disk, with no learning store, since it would be
  shared by every caller. docs/HOSTS.md lists what changes from stdio,
  including `nyholm/psr7` in the example's `composer.json`. `ToolsHandler`
  moves to `bridge.php`, shared by both servers.

- **A Japanese veterinary clinic on the search benchmark.** 40 tools named
  in snake_case with titles and descriptions in Japanese, written without
  spaces in three scripts, many of them neighbours (book, move, cancel an
  appointment; prescribe, refill, cancel a prescription), and 100 requests
  written before any of them was run. It is the first measure of the pairs
  of characters on Japanese: 33 of its 50 test requests find the right tool
  first in fields, 26 as one sentence, and learning loses nothing. The
  benchmark now holds 1597 requests over fifteen servers; its floors move to
  617 and 719 in fields and 674 asked again.

### Changed

- **A word the tools do not use counts as the tool words alike it.** A
  request that writes "reservation" where the tools say "reserve" met them
  only by trigrams pooled with the rest of the request, so a tool sharing
  pieces of the word ("service") could come first. Each tool word whose
  trigrams are alike the whole word now also counts as a word match, at half
  the weight. On the test half of the benchmark: 883 requests in fields find
  the right tool first instead of 879, 628 as one sentence instead of 600
  (825 in the top 3 instead of 793). A cached search costs a few hundredths
  of a millisecond more. An `IndexCache` now keeps the inverted trigrams
  with every index, not only for a host that learns, so the saved index of
  a host that does not learn is about a quarter larger, and its cache
  entries are rebuilt once.

- **The ReactPHP example bounds the sessions it keeps.** The process kept
  every session `initialize` opened for as long as it ran, so a caller that
  initialized again and again, or a client that left without a word, grew
  its memory without end. A session nobody listens on is now let go after
  600 seconds without a request (`MCP_SESSION_IDLE`), one with an open
  stream is kept while the stream is; a caller holds 16 sessions at most,
  and a seventeenth lets its least recently used one go; and a `DELETE`
  naming the session ends it at once (204), as the specification lets a
  client do. A request on a session that is gone gets 404, which tells the
  client to initialize again.

- **A full learning store no longer sorts every count at each run.** Once
  `MemoryLearningStore` or `FileLearningStore` held its 20,000 counts, each
  run that learned a new word or pair sorted them all to drop the least run:
  about 19 ms on every such run, for every caller. Going past the limit now
  cuts the store to 18,000, so the next 2,000 new pairs need no sort; a full
  memory store learns in 0.7 ms a run instead of 19, a file store in 12 ms
  instead of 34. What goes is still the least run, the oldest first among
  equals, and the benchmark, which never fills a store, is unchanged.

- **Burmese is searched by pairs of what is seen, as Thai is.** A run of
  Burmese was one token that met tools only by its trigrams. It is now cut
  into overlapping pairs of grapheme clusters, the run kept beside them. On
  the test half of the new rice mill, as many requests find the right tool
  first in fields (36 of 50), 45 are in the top 3 against 42, fewer tools are
  returned (2.36 against 2.42), and learning no longer loses a request; one
  request as one sentence leaves the top 3. Chosen on its train half: 45
  first against 43. Khmer, measured the same way on the water supply office,
  stays read by its trigrams. The index format changes, so a cached index is
  rebuilt once.

- **The stdio examples keep their search index and learn.** The server
  written by hand over stdio and the two on the official PHP SDK built the
  index again at every search and never learned, so a host that started
  from them did the same. Each now gives `Discovery` one `SearchSetup` for
  the life of the process, with a `MemoryIndexCache` and a
  `MemoryLearningStore`: the process serves one caller, so what its runs
  teach lifts only that caller's tools, for its session. The registry
  example still builds its gate and `Discovery` per request and shares the
  setup between them. A test in each holds them to it: a search names the
  tool that adds a note first for "open a note", a failed read with its
  `search_id` changes nothing, and after one that succeeds the read comes
  first.

- **Learning no longer pairs the characters of one word.** A word of
  Japanese, Chinese, Korean or Thai is cut into pairs of characters, and
  learning paired those pairs with each other: they only said the object
  again ("飼い主", owner), so a search for an owner's address lifted that tool
  for every later request about an owner. Terms are now paired only across
  tokens (the verb with its object) when a request has several, and a
  sentence without spaces still keeps every pair. Requests in an alphabet
  learn exactly as before. On the test half, after learning from the other
  half, 587 requests find their tool first instead of 586, and 638 instead
  of 637 when asked again; the floor of the benchmark moves to 638. The
  `search_id` keeps the terms of each token together; an id from before
  this reads as one token per word, and a learning store needs no change.

- **The grouped list says how to describe an action once, not twice.** Each
  group's read tool told the model how to read an action's parameters in
  its description, then again in the description of its `describe`
  property, and a client sends both. The sentence in the description is
  gone; the property says it, now with "before running them". Nothing else
  changes: the same tools, the same schemas, the same answers. On the
  workspace benchmark server the grouped list goes from 14,314 to 13,982
  bytes, and its eight group tools from 7,486 to 7,154, almost a quarter
  under the 9,299 of the actions they stand for.

### Fixed

- **The same search finds the same tools on every version of PHP.** The
  Analyzer lowered text with Unicode's full mapping, which from PHP 8.3
  chooses the Greek final sigma by context: a request typed in capitals
  (`ΑΚΥΡΩΣΗΣ ΚΡΑΤΗΣΗΣ`) gave other terms, and could find other tools, on 8.2
  than on 8.3. The same mapping lowered the Turkish `İ` to an `i` and a
  combining dot, so a tool titled "İzin kaydet" never met the word `izin`.
  Each letter is now lowered on its own, with the simple mapping: a capital
  sigma always becomes `σ`, as on 8.2, and `İ` becomes `i`. The index
  format moves to 8, so a cached index is built again once.

- **The parameters added to a failed run keep their objects.** With
  `schemaOnError`, a run that fails carries the tool's parameters so the
  assistant can correct its call. They were written as the host gave them,
  so a host that read its definitions with `json_decode(..., true)`, as the
  SDK registry example does, sent a tool with no parameter as
  `"properties": []`: a list where the search had shown an object. They are
  now written as the search writes them, in Compact, Grouped and Gateway.

## 0.5.0 - 2026-11-18

### Added

- **A Russian hotel on the search benchmark.** The front desk of a hotel,
  40 tools named in SCREAMING_SNAKE_CASE with titles and descriptions in
  Russian, many of them neighbours (book, move, cancel a booking; close a
  room for repairs and open it again), and 100 requests written before any
  run. Nothing in the search was changed for it: 37 of its 50 test requests
  find the right tool first in fields (47 in the top 3) and learning from its
  other half loses none, which it did before a word written another way was
  learned as the closest tool word. The benchmark now counts 1497 requests
  over fourteen servers; the test half gives 584 first in fields and 682 in
  the top 3, and the floors of the benchmark test are raised with it.

- **The gateway marks each tool that changes something.** Through the
  gateway alone, every tool a search, a description or the bare list gives
  answers to the same `run_with`, `use_tools`, so nothing told the model
  which calls change something, while the gateway's description asked it to
  confirm those with the person. Each such tool now carries
  `"changes": true`; reads carry nothing, so a server that only reads pays
  nothing, and with `changesApart`, where `run_with` already names each
  change, the mark is left out. The compact and grouped modes do not change:
  their `run_with` already tells the two apart. The gateway's list is 6
  bytes shorter. `ToolFinder` takes a last, optional `$markChanges`.

- **Korean is searched by pairs of syllables.** Korean glues to each word
  the particles that mark its role, so "레슨을" (the lesson, as object) never
  met a tool that writes "레슨". Each Korean word is now cut into overlapping
  pairs of syllables, as Lucene's CJK analyzer does; the caller is still
  shown the words it wrote. On a new benchmark server written in Korean (40
  tools, 100 requests written before any run), the test half goes from 36
  to 37 first in fields (40 to 44 in the top 3) and from 34 to 36 as one
  sentence, with fewer tools returned; the eleven other servers do not move.
  The saved index format changes, so an index in `FileIndexCache` is rebuilt
  once.

- **A Laravel application among the host examples.** docs/examples/laravel
  serves the notes server over HTTP in Laravel 13: an `mcp` guard added with
  `Auth::viaRequest()` finds the caller from the bearer token, an ability of
  Laravel's gate decides which tools they see (`AbilityGate::visible()` asks
  `allows()` for the caller), the route sits outside the `web` group, and the
  controller builds the gate and the `Discovery` for each request from the
  shared singletons. It answers from the same docs/examples/http.php, and its
  test asks it all the other HTTP servers are asked, plus one application
  serving callers in turn, as Octane does. docs/HOSTS.md says why a worker
  must forget the guards between two requests: a guard keeps the user it
  found, and without that a request with no token was served as the last
  caller. A CI job, part of **CI passed**, installs the pinned
  `laravel/framework` 13.43.0 on PHP 8.3 and runs PHPStan and the tests.

- **Thai is searched by pairs of what is seen.** It is written without
  spaces, so a sentence was one word that met tools only by its trigrams. A
  run of Thai is now cut into overlapping pairs of grapheme clusters (a
  consonant with its vowel and tone marks), and the run is kept beside them;
  a run of Thai is also a token apart from the digits around it. On a new
  benchmark server written in Thai (40 tools, 100 requests written before
  any run), the test half goes from 34 to 37 first in fields (47 to 48 in
  the top 3) and from 30 to 31 as one sentence; asked a second time, the
  whole test half gains 10 first. The ten other servers do not move. The
  saved index format changes, so an index in `FileIndexCache` is rebuilt
  once.

- **Chinese and Japanese are searched by pairs of characters.** They are
  written without spaces, so a sentence was one word that met tools only by
  its trigrams, and a two-character word ("报修") never met them at all. A
  run of Han or kana is now cut into overlapping pairs, with no dictionary
  and no list; other scripts are cut as before. On a new benchmark server
  written in Chinese (40 tools, 100 requests written before any run), the
  test half goes from 30 to 40 first in fields and from 17 to 26 as one
  sentence (21 to 34 in the top 3); the eight other servers do not move.
  `unmatched` and `vocabulary()` still give whole words, never the pairs, and
  a host synonym can name a pair. `Analyzer::tokens()` gives the words as
  written and `Analyzer::terms()` the terms of one of them. The saved index
  format changes, so an index in `FileIndexCache` is rebuilt once; what a
  learning store holds for Chinese or Japanese searches was kept by whole
  sentence and no longer lifts anything.

- **The scores the docs give are the ones the benchmark measures.**
  `tests/Search/BenchmarkTest.php` now reads the test-half table of the
  README and of docs/SEARCH.md, and the sentence that sums the fields up,
  and fails when one of them differs from what the benchmark measures. A
  change to the search can no longer leave a page behind.

- **Every snippet that builds a mode is run in CI.**
  `tests/Docs/ModeSnippetsTest.php` runs the mode snippets of the README and
  of docs/MODES.md as a reader copies each page, then lists each mode's tools
  on every revision and calls them with the arguments the modes take and the
  JSON examples the docs show. It fails on a warning, on a hidden tool listed
  or run, and on a mode that never reaches a tool.

- **The README's quick start is run in CI.** `tests/Docs/QuickStartTest.php`
  runs its blocks as one script, with only the host code they name around
  them, and checks what they answer: the compact list, a visible tool run by
  its name, a hidden one refused. Until now the snippets were only read, so a
  gate that no longer fit `ToolGate`, or blocks that worked only one by one,
  passed the docs tests and failed in the first project that copied them.

### Changed

- **A host tool named like one of the mode's tools now stops the host.**
  `Discovery` used to leave such a tool out of every list and every call
  without a word: a host tool `files` next to a grouped group `files`, or a
  tool `search_tools` under the compact mode, simply vanished, for every
  caller. `listTools()`, `callTool()` and `call()` now throw `InvalidTool`,
  naming the tool and the mode, on the first request that meets it. The check
  runs before the gate, so it reads the same for every caller and says
  nothing about what one may see. A server with such a clash fails where it
  silently lost a tool before: give the mode a `prefix` or rename the tool.

- **The Symfony example uses the security bundle.** A stateless firewall
  with `access_token` finds the caller, and a voter decides which tools they
  see: `SecurityGate::visible()` asks `isGranted()`, so the role hierarchy
  and the application's other voters apply to tools as to anything else. The
  controller no longer reads the token, and a request with no token reaches
  it with no user and is answered 401. What each tool checks in its
  arguments and does moved from `NotesGate` to `NotesTools` in
  docs/examples/notes.php, so a gate of the host's framework reuses it;
  `NotesGate` keeps its constructor. The example pins
  `symfony/security-bundle` 6.4.49, and its test now serves a request with no
  token between two callers on one kernel, as a worker does.

- **The HTTP examples leave the caller to the server.** `answerHttp()` in
  docs/examples/http.php no longer reads the bearer token: it takes a
  closure that gives the `Discovery` of the caller, or null for a request
  that names no one (still answered 401, before the body is read). The token
  is read by `roleOf()`, which the PHP-FPM and ReactPHP servers call. A
  host that copied `answerHttp()` and authenticates in its framework (a
  firewall, a middleware) no longer has the token checked a second time
  against the example's list.

### Fixed

- **Learning pairs a verb written another way with its object again.**
  Learning kept only the words of a request that the tools use, and the one
  that tells two neighbours apart is often the verb, which a request rarely
  writes as the tool does ("approving" for "approve", "أرسل" for "يرسل"). It
  found the tool by its trigrams but was left out of what was learned, so a
  search taught only its object, and lifted the wrong neighbour for the next
  request about that object. Such a word is now kept as the tool word closest
  to it by trigrams (a Dice coefficient of 0.3 at least), so a store still
  holds nothing but the tools' words. On the benchmark, learning from the
  other half gains 3 test requests and loses none; a new server written in
  Arabic (a car repair workshop, 40 tools, 100 requests written before any
  run) lost 2 to learning before and now loses none. The closest word is only
  looked for when the host learns, and only for words the tools do not use.
  With an `IndexCache`, the trigrams of the tool words it compares are kept
  with the index of a host that learns, inverted, so only the words sharing
  a trigram are visited: a search that learns over 176 tools takes about
  0.6 ms instead of 0.4 ms, where cutting every tool word again at each
  request took more than 6 ms. The cache of such a host is about a quarter
  larger; that of a host that does not learn is unchanged. Nothing changes
  without a `LearningStore`, and a store filled before keeps working.

- **Hindi, Thai, Arabic and every script that writes vowels as marks are
  searched by whole words.** The Analyzer split words on any character that
  was not a letter or a digit, and a mark (a Devanagari vowel sign, a Thai
  tone mark, an Arabic vowel point, an accent in decomposed text) is
  neither, so "सर्वर" became "सर" and "वर", fragments that met unrelated
  tools. Marks now stay in their word. On a new benchmark server written in
  Hindi (40 tools, 100 requests written before any run), the test half goes
  from 36 to 39 first in fields and from 15 to 23 as one sentence, and a
  search returns about half as many tools; the nine other servers do not
  move. The saved index format changes, so an index in `FileIndexCache` is
  rebuilt once; what a learning store holds for these scripts was kept as
  fragments and no longer lifts anything.

- **docs/SEARCH.md gives the current scores at the top of the page.** It
  still said 202 of 250 requests found first in fields and 94 as one
  sentence, from a benchmark of fewer servers; it now gives the scores the
  benchmark measures.

- **The `groupOf` snippet of the compact and gateway modes no longer breaks
  the search.** The README and docs/MODES.md showed
  `fn (Tool $t) => $myGroups[$t->name]`: on a server with one tool outside
  the map, a search with no argument raised a warning, then a `TypeError`
  from inside the library. The snippets now fall back to a group of their
  own (`?? 'other'`), and a `groupOf` that returns anything but a string now
  throws an `UnexpectedValueException` that names the tool it missed.

- **The grouped mode names the tool its `groupOf` missed, as the other modes
  do.** A `groupOf` that returned null or anything but a string for one tool
  stopped the list with a `TypeError` on a private method of `GroupedMode`,
  which named neither the tool nor the callable. It now throws the same
  `UnexpectedValueException` as the compact and gateway modes, with the name
  of the tool.

## 0.4.0 - 2026-11-17

### Added

- **An eighth benchmark server, written as many real servers are.** A
  newsroom: 40 tools in snake_case with no title, so the name stands in for
  one, and descriptions of 18 to 70 words that open with "Use this tool to"
  and point at their neighbours. Every other fixture has short descriptions
  and a title on every tool. Its 100 requests were written before any of
  them was run, and nothing was tuned on it: 35 of its 50 test requests find
  the right tool first in fields, 46 in the top 3, 30 as one sentence.
  Learning from its train half costs it one request, as on the school. The
  floors of `tests/Search/BenchmarkTest.php` move to the new counts on the
  449 test requests (362 first, 414 in the top 3, 388 asked again), so they
  still guard every request that passes today.
- **A server in a Symfony application**, `docs/examples/symfony`: the HTTP
  example on Symfony 6.4, the long-term release that runs on PHP 8.1. It shows
  which of the library's parts are services of the container (`SearchSetup`
  with its index cache, shared by every caller) and which must not be (the
  gate and the `Discovery`, built by the controller in each request, since a
  worker such as FrankenPHP keeps the container from one request to the
  next). It answers from the same `docs/examples/http.php`, and the tests ask
  it everything they ask the PHP-FPM server, plus one kernel serving callers
  in turn. A CI job installs the pinned `symfony/framework-bundle` and runs
  them; it is part of **CI passed**.
- **A server that stays up, on ReactPHP**, `docs/examples/react/server.php`:
  the HTTP example in one long-running process, as a ReactPHP, RoadRunner,
  Swoole or FrankenPHP worker runs it. It shows what such a host keeps between
  requests (one `MemoryIndexCache` in one shared `SearchSetup`) and what it
  must not (the gate and the `Discovery`, built inside each request). Both
  HTTP servers now answer from the same `docs/examples/http.php`, and the tests
  ask the ReactPHP server everything they ask the PHP-FPM one, plus callers
  taking turns in one process. A CI job installs the pinned `react/http` and
  runs them; it is part of **CI passed**.
- **Direct mode**, `src/Mode/Direct/DirectMode.php`: every tool the caller
  may see, listed as itself, and no tool of the mode's own. For a server
  small enough to list everything, and for a host that lets its users turn
  the other modes off: `$settings->compact ? new CompactMode() : new
  DirectMode()` keeps one path for `tools/list` and `tools/call`, where such
  a host used to need a second one that had to filter with the gate and shape
  each revision again on its own. Held to the mode contract and added to the
  conformance checks.
- **What each mode costs**, measured: `composer benchmark` now also prints
  the bytes of JSON of each mode's list and of one compact search answer on
  the six benchmark servers, and docs/MODES.md opens with "Choosing a mode",
  that table and when to take each mode. On the server with 176 tools the
  whole list is 60,640 bytes, the compact list 2,871 and the gateway list
  1,845, whatever the number of tools. `tests/Search/ListCostTest.php`
  guards those claims.
- **`Revision::negotiate()`**, the revision to answer `initialize` with: the
  client's own when the library shapes it, otherwise the latest revision with
  a handshake, as the specification asks. It takes `protocolVersion` as the
  client sent it, missing or not a string included, and never answers with
  the stateless revision, which has no handshake. **`Revision::latestWithHandshake()`**
  gives that fallback on its own. A host no longer names a revision of its
  own to fall back to, so it moves on when the library learns a newer one;
  the examples in docs/HOSTS.md use both.
- **The docs are tested.** `tests/Docs/DocsTest.php` reads every PHP snippet
  of the README and docs/ without running it: each must parse, and each class,
  method, enum case and named argument of the library it names must exist,
  with the case Composer's autoloader needs. Every relative link must lead to
  a file and its anchor to a heading. A snippet a host copies can no longer
  name a parameter the library renamed. Each class of the library a page
  names comes with its `use` line, in that block or one above it, and a name
  one typo or one case away from a class of the library (`new DirectMod()`,
  `new toolSet()`) fails instead of passing for a class of the host. The
  snippets of the grouped and gateway modes in the README, and those of
  docs/MODES.md and docs/SEARCH.md, now show their `use` lines: copied as
  they were, they stopped at "Class not found".

### Changed

- **Arguments sent as a JSON array never reach the gate.** `json_decode()`
  turns a JSON array into a PHP array as it does an object, so a host that
  checks `is_array()` and a mode that read `arguments` the same way handed a
  gate `[0 => ...]` where it expects named arguments. Only the batch runner
  refused that. `Discovery::call()` and `ToolRunner::run()` now answer
  "arguments must be an object." with `isError` before the mode or the gate
  sees anything, and the compact, grouped and gateway runners give the same
  answer for the `arguments` they carry. Both methods document what they
  really accept, `array<mixed>`, so static analysis no longer takes the check
  for dead code. `ToolRunner::isObject()` is the one rule they share, for
  modes written outside the library too; the empty array still stands for
  `{}`. The mode contract tests check it for every mode.
- **Grouped mode, a lighter list.** Each action used to be named up to four
  times: in the read tool's description, in its `action` or `describe` enum,
  and again in the change tool. It is now named twice, a line with its title
  in the tool that runs it and that tool's `action` enum. The read tool no
  longer repeats the changes, it names the change tool instead, and
  `describe` takes plain strings (an unknown name still comes back in
  `unknown`). A tool without a title is listed by its name alone rather than
  "name: name". On the workspace benchmark server the grouped list goes from
  15,444 to 14,314 bytes, and its eight group tools from 8,616 to 7,486.
  Clients that relied on the `describe` enum to check names now get the
  same answer from the call.

### Security

- `CallResult::json()` replaces each sequence that is not valid UTF-8 with
  U+FFFD, in the text and in the structured content alike, so an answer that
  carries a host tool's output, a `run_write_batch` report included, can
  always be encoded and reaches the client.
- `MemoryLearningStore` and `FileLearningStore` keep at most 20,000 counts
  of a word or pair for a tool, the most used ones, so what a store holds and
  what each search reads stay the same size however long it serves.

## 0.3.0 - 2026-11-16

### Added

- **Gateway mode**, `src/Mode/Gateway/GatewayMode.php`: a single tool,
  `use_tools`, for clients that accept very few tools. Each call gives an
  `operation`: `search` (the compact search, same fields and same answer),
  `describe` (`names`) or `run` (`name`, `arguments`, `search_id`, passed to
  the runner so the search learns). The tool is marked destructive as soon as
  the caller may run a change through it, and as a read when every visible
  tool only reads. With `changesApart: true` the changes are listed as
  themselves with their own annotations, the gateway only reads, and a change
  sent through `run` is refused before the gate is asked. Options: `prefix`,
  `title`, `changesApart`, `groupOf`, `maxDescribed`, `schemaOnError`. It
  passes the mode contract in both settings and is in the conformance checks.
- **Grouped mode**, `src/Mode/Grouped/GroupedMode.php`. The host declares its
  groups (`groups`: name => title) and says where each tool belongs
  (`groupOf`); each group holding a visible tool becomes a tool named after
  it, which runs the group's reads and describes any of its actions with
  `describe`, and `<group>_change`, destructive, which runs its changes. Every
  action of a group is listed in its tool's description and in an `enum`, so
  the client sees the map of the server without searching. An action sent to
  the wrong tool is refused before the gate is asked; a hidden one is answered
  like one that does not exist. A tool outside the declared groups is listed
  as itself. Options: `prefix`, `changeTitles`, `maxDescribed`,
  `schemaOnError`. It passes the mode contract and is in the conformance
  checks.
- **`ModeContractTestCase::searches()`**: a mode that does not search
  answers false, and the contract then checks it never teaches the search.

- **`run_write_batch` in compact mode**, off by default. `batchLimit: N` on
  `CompactMode` adds a fourth tool that runs one change tool once for each
  item of a list (`name`, `items`, `search_id`), up to N items a call, so a
  client changing thirty items asks the person once instead of thirty times.
  Each item goes through the gate on its own, as a call of `run_write_tool`
  would; the list is refused whole, before anything runs, when it is
  malformed, longer than the limit, or names a read, a runner or a tool the
  caller may not see. The answer reports `succeeded`, `failed` and each item's
  result, and is an error only when no item succeeded. The tool carries
  `destructiveHint`, tells the client to show the complete list before
  confirming, and the search learns once per batch. With the option left at
  0, the list of tools is unchanged and a host tool of that name stays the
  host's.
- **A server on the official PHP SDK, in `docs/examples/sdk/`.** The notes
  server on `mcp/sdk` 0.10.0 over stdio: one request handler answers
  `tools/list` and `tools/call` from the library, and the SDK keeps the
  handshake, sessions and the stateless revision. `docs/HOSTS.md` lists the
  five things a host on the SDK writes, among them decoding the arguments
  again as arrays, since the SDK hands nested objects over as `stdClass`. A
  CI job of its own installs the SDK at that exact version and runs the new
  `sdk` test suite; it stays out of **CI passed** while the SDK is below 1.0,
  and `mcp/sdk` never enters the library's `composer.json`.
- **A server over HTTP, in `docs/examples/http-server.php`.** The notes
  server served over the Streamable HTTP transport, one process per request as
  under PHP-FPM: the gate built from the request's `Authorization` header, the
  revision read from `MCP-Protocol-Version` after `initialize` (`2025-03-26`
  without it, 400 for one the library does not shape), the search index kept
  in a `FileIndexCache` between requests, and the 202, 401, 403 and 405 a
  host answers itself. `docs/HOSTS.md` says what changes from stdio. The tests
  run it behind PHP's built-in server and call it over HTTP. It also serves
  the stateless revision `2026-07-28`: `server/discover`, the revision read
  from each request's `_meta` and checked against the header, and error
  -32022 with the supported revisions for one it does not speak. The tools, the
  gate and the choice of revision moved to `docs/examples/notes.php`, shared
  by both examples.
- **A complete server written by hand, in `docs/examples/`.** A stdio MCP
  server with no SDK, in one file: four note tools, a gate that hides one of
  them from all but an admin, the compact mode with the batch runner, the
  revision negotiated at `initialize`, and the JSON-RPC errors a host keeps.
  `docs/HOSTS.md` walks through it. The new `examples` test suite runs it in
  its own process as a client would, and the coding standard, PHPStan at
  level max and the PHP 8.1 check read it, so the example stays true to the
  library.
- **A fourth benchmark server, in French.** `composer benchmark` now also
  measures a network of public libraries: 40 tools with kebab-case names and
  French titles and descriptions, and 100 requests written before any of them
  was run. Nothing in the search changed for it: 39 of its 50 test requests
  find the right tool first in fields. The test half is now 200 requests, and
  the floors in `BenchmarkTest` are the new counts (164 first, 183 in the
  top 3, 174 asked again); the share shown by the badge moves from 83% to 82%
  because the new server is harder, not because the search got worse.
- **A fifth benchmark server, in German.** The office of a school: 40 tools
  with PascalCase names (`KlassenfahrtAbsagen`) and German titles and
  descriptions, and 100 requests written before any of them was run. Nothing
  in the search changed for it: 38 of its 50 test requests find the right
  tool first in fields, 44 in the top 3. Learning from its train half moves
  one of them out of first place, while the benchmark as a whole still gains
  one: the first server where learned words met new requests and cost one.
  The test half is now 250 requests, and the floors in
  `BenchmarkTest` are the new counts (202 first, 227 in the top 3, 215 asked
  again); the badge moves from 82% to 81% for the same reason as above.
- **A sixth benchmark server, with 176 tools.** A cloud hosting panel in
  English with snake_case names (`nimbo_detach_volume`), most tools sharing
  an object with several neighbours (stop, power off, reboot a server;
  snapshot, back up, restore), and 197 requests written before any of them
  was run. The weights and the cut were not touched: 84 of its 99 test
  requests find the right tool first in fields, 94 in the top 3. The test
  half is now 349 requests, and the floors in `BenchmarkTest` are the new
  counts (286 first, 321 in the top 3, 304 asked again); the badge moves from
  81% to 82%.
- **A seventh benchmark server, in Greek.** The service desk of a town hall:
  40 tools with English snake_case names (`parking_permit_renew`) and Greek
  titles and descriptions, and 100 requests written before any of them was
  run, about half typed without accents as many people type Greek. Nothing
  in the search changed for it: 41 of its 50 test requests find the right
  tool first in fields, 47 in the top 3; learning from its train half moves
  none of them, and asked a second time 44 come first. Written with accents, 24 of 26 come first; without, 17 of 24. The
  test half is now 399 requests, and the floors in `BenchmarkTest` are the
  new counts (327 first, 368 in the top 3, 348 asked again); the badge stays
  at 82%.

### Changed

- **Learning works by pairs of words.** A run that succeeds now records the
  pairs of words of its search beside the words, and a request of two words
  or more is lifted by the pairs it shares with past searches. Word by word,
  one run of "get the certificate" lifted the tool that shows a certificate
  for every request naming a certificate, "delete" ones included: on the
  176-tool server, learning from the train half cost 3 test requests their
  first place, and the benchmark broke its own rule that learning never
  hurts. By pairs it costs none, and learning from other requests now gains
  2 (288 first against 286 with nothing learned). Repeated requests lose one
  first place against word by word (304 against 305) and gain one in the top
  3. A request of one word still uses the single words. Nothing changes in
  `LearningStore`: a pair is one more key, two words with a space between.
  A store filled before keeps lifting requests of one word and learns the
  pairs from the next runs, so a request of several words starts again from
  what the text finds. The pairs come from at most 16 words of a search.
- The compact search moved to `src/Mode/ToolFinder.php`, shared with the
  gateway mode, so both answer a request with the same tools. Its answers
  are unchanged.
- **A cached search is about five times faster.** `ToolIndex` is now an
  inverted index: each word and trigram points to the tools that hold it,
  with its weight there worked out when the index is built, so a search only
  visits the tools that share a term with the request instead of every tool
  for every term. With a cache, a search over 500 tools goes from about 6 ms
  to about 1 ms, over 50 tools from 0.6 ms to 0.1 ms; building the index costs
  the same. The scores are identical to the last digit, so the ranking and the
  benchmark do not move. An index saved by an `IndexCache` in the former shape
  is rebuilt once, on its own; the new one is about a third larger on disk.
- **Building the index is about a third faster**, which is the cost of every
  search made without a cache: over 500 tools it goes from about 25 ms to
  about 16 ms, over 50 tools from 2.3 ms to 1.5 ms. Each word is cut into
  trigrams once per build instead of once per field it appears in, and a word
  written in ASCII is cut by bytes rather than split into characters. The
  index is the same to the last digit, saved shape included, so a cache entry
  stays valid and the ranking does not move.

### Fixed

- A tool whose name is only digits, such as `"123"`, no longer breaks the
  search: every `search_tools` call (and `use_tools` with `search`) of a
  server holding one failed with a `TypeError`, in every mode. PHP turns such
  a name into an integer array key; `ToolSet` now hands out the tool's own
  name everywhere, so `names()` and a `foreach` over the set always give
  strings. `ToolSet::getIterator()` returns a `Generator` instead of an
  `ArrayIterator`, which a host only notices if it called `ArrayIterator`
  methods on it.

### Security

- A search reads at most the first 1024 bytes of each field of a request
  (`action`, `object`, `description`, `query`), cut between two characters,
  so its cost stays small whatever the size of the request.
- The notes example shared by the stdio and HTTP servers cuts the start of
  each note it lists between two characters, so its list answers for notes
  written in any alphabet.

## 0.2.0 - 2026-11-14

### Changed

- **`Search\Index` is now `Search\ToolIndex`.** A host that drops an
  `index.php` in every folder of its package, as some plugin stores require,
  put it beside `Index.php`, and unpacked on a filesystem that ignores case one
  of the two replaced the other. Code that built or restored the index itself
  (`Index::build()`, `Index::fromArray()`) uses the new name; arrays saved by
  an `IndexCache` are read back unchanged.

### Added

- `bin/check-hygiene.php` refuses two paths that differ by case alone, and a
  file named `index.php` in any case.

## 0.1.0 - 2026-11-14

### Added

- **Compact mode.** A server shows its clients three tools instead of its whole
  list: `search_tools` to find a tool and read its parameters, then
  `run_read_tool` or `run_write_tool` to run it. The change runner carries
  `destructiveHint`, so clients ask the person before a change, and each runner
  refuses the other kind of tool. Options: a prefix for the three names, tools
  kept in the list as themselves, translated titles and groups for the full
  list.
- **Search settings for every mode.** `SearchSetup` holds the search options,
  the cache and the learning store, both optional, and is given once to
  `Discovery`. Modes search through `ToolRunner::search()`, and the runner
  learns from a run that succeeded, so a server that changes modes keeps what
  it learned and no mode can skip the checks.
- **Tool search in fields.** The client says the action, the object and the
  request in its own words in separate fields of `search_tools`; each field is
  scored on its own with BM25F over the name, title, description and
  parameters of each tool, with trigrams for plurals and near spellings. On 150
  requests kept aside over three servers named in three styles, the right tool
  comes first 125 times, against 57 for the same requests as one sentence. Free
  keywords still work.
- **No list and no naming convention.** The search holds no stop words, no
  verbs and no prefix rule, in any language: a word every tool carries weighs
  almost nothing by itself. Synonyms and stop words are the host's, when it
  gives them.
- **Words to say it again.** When words of a search match no tool, the answer
  lists them and gives the words of the visible tools' titles.
- **Index cache.** `MemoryIndexCache` for a long-running server,
  `FileIndexCache` for one where each request starts afresh, read through
  OPcache. One index per set of visible tools, so a caller never reads an index
  built over tools hidden from them.
- **Learning, opt in.** With a `LearningStore`, a search returns a `search_id`
  and a run that succeeds with it lifts that tool for those words next time.
  Only words the tools use are kept, never what a person typed beyond them;
  what was learned reorders the tools a query finds, within a cap, and never
  brings a tool the query does not describe.
- **The host keeps the control.** `ToolGate` is the one interface a server
  implements: what the caller may see, whether a call may go ahead, and the
  call itself. A mode only reaches tools through `ToolRunner`, which asks the
  gate first.
- **Answers shaped for each revision of MCP**, from `2024-11-05` to
  `2026-07-28`, validated in CI against each revision's official schema. Empty
  PHP arrays that must be JSON objects are sent as objects.
- **A contract for modes.** `DiscoveryMode` and the contract tests every mode
  must pass, for the Grouped and Gateway modes open to contribution.
