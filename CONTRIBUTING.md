# Contributing

Thanks for looking. This library is small on purpose, and the most useful
contributions are the two modes still to write, measured improvements to the
search, and reports from servers that use it.

Before anything large, open an issue or comment on an existing one, so we agree
on the shape before you spend an evening on it.

---

## Branches

- **`develop`** receives every change, through a pull request.
- **`main`** only receives `develop`, merged by the maintainer for a release.
  A pull request to `main` from any other branch fails the **Branches** check.
- Branch from `develop` and name the branch after the change: `mode/grouped`,
  `search/field-weights`, `fix/empty-properties`.

## Setup

```
git clone https://github.com/novalo-dev/mcp-tool-discovery
cd mcp-tool-discovery
git checkout develop
composer install
php bin/fetch-schemas.php
composer check
```

`composer check` runs what the CI runs: the coding standard, PHP 8.1+
compatibility, PHPStan, the tests and the conformance suite. `composer cs-fix` fixes the coding standard, licence
headers included.

## Checks

Every pull request runs:

| Workflow | Job | What it checks |
|---|---|---|
| CI | Coding standard, static analysis, hygiene | PER-CS 2.0, PHPStan level max on `src/` and `bin/`, level 6 on `tests/`, the hygiene of files and commit messages |
| CI | PHP 8.1+ compatibility | PHPCompatibility reads every construct, function and class against each PHP from 8.1 up |
| CI | Tests on PHP 8.1 to 8.5 | PHPUnit: unit, contract, search and conformance suites |
| CI | What Composer installs | the package holds `src/` and the licence, not the tests |
| Conformance | Protocol conformance and search benchmark | the scores the README badges show |
| Security | Dependencies, Secrets, Semgrep, Psalm taint analysis | `composer audit`, Gitleaks over the whole history, Semgrep rules for PHP and secrets, and Psalm following what a client sends (`tests/Security/entry.php`) to a file, a query, a command or an output |
| CLA | license/cla | the contributor has signed the [CLA](CLA.md) |

The required check is **CI passed**: one name, whatever the matrix becomes.

**Hygiene** (`bin/check-hygiene.php`) is specific to this repository:

- `src/` names no host framework, so the library stays usable by any server;
- every PHP file starts with the licence header;
- no long dash and no stock phrase: write plainly;
- no line, in a file or a commit message, that credits the work to a tool
  rather than to its authors.

---

## Writing code

- **PHP 8.1** is the floor: no syntax, function or class from a later version.
  `composer compat` checks every line; the CI also runs the tests on 8.1 to 8.5.
- **No dependency** in `require`, and no PHP extension beyond what PHP always
  has. A host on shared hosting must be able to install the library.
- **Comments say why**, in English. The reader sees what the line does.
- **Plain arrays at the edges**: the library takes and gives MCP structures as
  PHP arrays, so no host needs an adapter.
- **Names from the specification**: `inputSchema`, `readOnlyHint`,
  `structuredContent`, as the specification spells them.
- **Tests come with the change.** A fix comes with the test that failed before
  it.

## Writing a mode

A mode is the most useful contribution and the most guarded one, because it
stands between a client and a server's tools.

1. **Read** [docs/MODES.md](docs/MODES.md): what a mode is, and what the two
   wanted modes should do.
2. **Create the pack** in `src/Mode/<Name>/`, with a class that implements
   `DiscoveryMode`. Keep everything the mode needs in its folder, and use
   `src/Search/` if it searches.
3. **Pass the contract.** Add `tests/Contract/<Name>ModeContractTest.php`,
   extending `ModeContractTestCase`. It checks that the mode never lists,
   describes or runs a hidden tool, never runs without authorization, and
   flags the tools that run changes.
4. **Test what is yours** in `tests/Unit/<Name>ModeTest.php`: the list, each of
   the mode's tools, every refusal.
5. **Add it to the conformance suite**: one entry in
   `tests/Conformance/Conformance.php::checks()` per kind of answer the mode
   gives, so its answers are validated against every revision's schema.
6. **Document it**: its section in docs/MODES.md, its row in the modes table of
   the README, a line in CHANGELOG.md under `## Unreleased`.

A mode that fails the contract is not merged, however useful.

## Changing the search

The search is measured, not tuned by feel.

- `composer benchmark` prints the scores on the three fixture servers. A change
  must not lower them: `tests/Search/BenchmarkTest.php` holds the floors.
- A change that raises it raises the floors in the same pull request, and
  updates the numbers in docs/SEARCH.md and the README.
- New fixture queries go in `tests/Fixtures/queries.json`, written **before**
  running them against your change, so the benchmark keeps measuring and does
  not just repeat what was tuned. Settings are chosen on the `train` half and
  judged on the `test` half.
- The library holds no list of words and assumes no naming convention: no stop
  words, no verbs, no prefix to strip. A word of a business ("meeting",
  "admin") belongs in the host's synonyms, not in the library.

## Protocol revisions

- The conformance suite reads the official schemas from the commit in
  `Conformance::SPEC_COMMIT`. Moving it is a pull request of its own, with the
  scores before and after.
- A new revision means a new case in `Revision`, its fields in `toolFields()`,
  its behaviour in the other methods, and a row in the README table.

---

## Contributor License Agreement

Before your first pull request is merged, you sign the [CLA](CLA.md). You keep
the copyright of your contribution; you allow the maintainer to distribute it
under the MIT licence and under any other. The CLA Assistant bot posts the link
on your pull request; you sign once, with your GitHub account.

## Commits and pull requests

- One subject per commit. The message says what was wrong and why the change is
  the right fix, not what the diff already shows.
- A line in `CHANGELOG.md` under `## Unreleased`, for the developers who use the
  library: what they will notice.
- Never change the version: there is none in `composer.json`, and the
  maintainer writes the version section of the CHANGELOG at release time.
- Fill in the pull request template. A pull request from a fork runs its CI
  once the maintainer approves it.

## Reporting a vulnerability

Privately, never in an issue: see [SECURITY.md](SECURITY.md).

## Code of conduct

[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) applies to everyone, everywhere in
the project.
