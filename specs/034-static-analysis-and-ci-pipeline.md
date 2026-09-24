# Spec 034 — Static analysis, code style and the CI quality pipeline

## Metadata

- Status: Implemented
- Created: 2026-09-23
- Updated: 2026-09-23
- Owner: Henry
- Related issue: Not applicable (roadmap items — `docs/ROADMAP.md`, `v1.8.0 — Reliability & Quality`: "Static analysis", "Code style", "CI quality pipeline")
- Related branch: `034`

## Context

Second work item of the `v1.8.0 — Reliability & Quality` milestone, after spec 033 (job
reliability). The roadmap lists "Static analysis", "Code style" and "CI quality pipeline" as
three separate subsections, but they are one piece of work: they share a single goal (stop
regressions before merge), touch the same two files (`composer.json`,
`.github/workflows/ci.yml`), and splitting them would produce three pull requests that only
make sense merged together. They are therefore covered by this one spec, as agreed with the
owner before drafting.

This is also the last "confirmed gap" carried by the project baseline:
`specs/000-project-baseline.md:143` records that the lack of automated tests was closed by
specs 004/005 and that **"no static analysis/lint tooling remains the one still-open gap."**
This spec closes it.

## Problem

1. **Nothing catches a type error before runtime.** `CLAUDE.md` states plainly: "There is
   still no lint/static-analysis command in this project (no PHPStan/Psalm/CS-Fixer
   configured)." A typo in a property name, an impossible comparison, a call to a method that
   does not exist, or a wrong argument type reaches production unless a unit test happens to
   cover that line.
2. **Style is enforced only by reviewer attention.** The codebase has real conventions
   (documented in `CLAUDE.md`), but nothing mechanical checks them, so drift is invisible until
   a diff looks odd.
3. **CI proves only that tests pass.** `.github/workflows/ci.yml` runs `composer install`,
   loads the schema, runs `bin/migrate`, then `vendor/bin/phpunit`. `composer validate
   --strict` and `composer audit` were run manually once for spec 017 and never wired in, so a
   malformed `composer.json` or a newly disclosed CVE in a dependency would not fail a build.

## Goals

- A single command reports static-analysis problems in `src/`, and CI fails when new ones appear.
- A single command reports style violations without rewriting any file, and CI fails on violations.
- CI runs the roadmap's pipeline in order, and a failure identifies which stage failed.
- The starting strictness is chosen from a real measurement of this codebase, not from ambition.

## Non-goals

- **No Psalm.** One static analyser is enough; adding a second doubles the baseline maintenance
  for overlapping findings.
- **No annotating the Eloquent models to raise the level.** Adding `@property` docblocks to ten
  models is real work with real value, but it is a separate change and would dwarf this spec's
  diff. This spec picks a level the code passes today and records what a higher level would cost.
- **No rewriting code to satisfy the analyser** beyond what the baseline requires. A finding that
  reveals a genuine bug gets its own spec, not a silent fix inside this one.
- **No behavior change.** No runtime (non-dev) dependency, no `src/` logic edited except where a
  style fixer reformats it.
- **No other `v1.8.0` items.** Integration tests, E2E smoke, printing/realtime reliability,
  structured logging, audit history, health checks, migration reliability and backup/restore are
  each their own later spec. The CI pipeline this spec builds leaves the integration-test stage
  for the spec that actually adds integration tests.

## Current behavior

Confirmed by reading the files on 2026-09-23.

**`composer.json`** — `require` holds 13 runtime packages (`slim/slim ^4.0`, `php-di/php-di
^7.0`, `illuminate/database ^10.0`, `monolog/monolog ^3.0`, `firebase/php-jwt ^7.0`,
`mike42/escpos-php ^2.2`, …) on `php >=8.1`. `require-dev` holds exactly one package:
`phpunit/phpunit ^11.0`. `autoload` maps PSR-4 `App\ → src/`. **There is no `autoload-dev`
section**, so the `Tests\` namespace used by `tests/Unit/*.php` is not registered with Composer
— PHPUnit loads those files directly via `phpunit.xml`'s directory scan plus
`tests/bootstrap.php`. Any tool that needs to resolve `Tests\Unit\...` by autoload will need
that entry added.

**`.github/workflows/ci.yml`** — triggers on push to `master` and on every `pull_request`. One
job, `test`, on `ubuntu-latest`, with a `mysql:8.0` service and a health check. Steps in order:
checkout → `shivammathur/setup-php@v2` (PHP 8.2, extensions `pdo, pdo_mysql, mbstring, xml,
zip`, composer v2) → `composer install --no-interaction --prefer-dist` → wait for MySQL and pipe
`common/sql/001_schema.sql` into it → write a `.env` from the job's env vars → `php bin/migrate`
→ `vendor/bin/phpunit`. There is no `composer validate`, no `composer audit`, no analysis and no
style stage.

**`phpunit.xml`** — `bootstrap="tests/bootstrap.php"`, two suites (`tests/Smoke`, `tests/Unit`),
`<source><include><directory>src</directory>`. 133 tests as of spec 033.

**Code shape — measured, not assumed:**

- **All 49 PHP files under `src/` already declare `strict_types=1`**
  (`grep -rL 'declare(strict_types=1)' --include='*.php' src/` returns nothing), as do all files
  under `tests/` and `bin/`. **This contradicts `CLAUDE.md`**, which still says
  `declare(strict_types=1)` "is used in newer files but not universally". That statement is
  stale and must be corrected by this spec.
- **None of the 10 Eloquent models carries a single `@property` docblock**
  (`grep -rc '@property' src/Models/` → `0` for every file). Every `$order->status`,
  `$job->reserved_until`, `$order->items` access therefore resolves through
  `Illuminate\Database\Eloquent\Model::__get()` and is `mixed` to a static analyser.
- Model helper methods are untyped: `public function items()` and
  `public function scopeStatus($query, $status)` in `src/Models/Order.php` have neither
  parameter types nor return types. The same shape repeats across the other models.
- `public/` holds 9 PHP files, and per `CLAUDE.md` these are plain view scripts mixing PHP and
  HTML that run **outside** Slim.

**Not verified in this session, stated rather than assumed:** whether `composer validate
--strict` and `composer audit` currently pass. Spec 017 recorded both passing clean at
`v1.6.0`, but Docker was unavailable while this spec was drafted
(`failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine`), so that
could not be re-confirmed. Implementation must run both before wiring them into CI — if either
fails today, that failure is a finding for this spec to report, not to quietly fix.

## Proposed behavior

### Static analysis — PHPStan

`phpstan/phpstan` as a `require-dev` dependency, configured by `phpstan.neon` at the project
root, analysing `src/` and `bin/`.

**The level is not pre-decided in this spec, and that is deliberate.** Choosing it requires
running the tool, which cannot be done while drafting (the skill that produces specs does not
install dependencies, and Docker was down regardless). The first implementation step is a
measurement: install PHPStan, run levels 0 through 8 against `src/`, and record the real error
count per level in the Implementation log. The level is then chosen by this rule:

> Adopt the **highest level whose findings can be driven to zero by the baseline file plus
> genuinely trivial annotations**, and where the baseline holds fewer than ~50 entries. If no
> level above 5 meets that, adopt the highest that does and record what the next level would cost.

Assessment going in, to be confirmed or corrected by the measurement: levels 0–4 should be close
to clean, because every file already declares `strict_types` and the code is conventional.
Level 6 (missing type hints) and above will collide hard with the untyped model methods and the
docblock-free magic properties above; `larastan`, which is what normally rescues this, targets
full Laravel and not the standalone `illuminate/database` this project uses, so it is not an
option. A large baseline at level 6+ would be a maintenance liability, which is why the rule
above prefers a lower clean level over a high dirty one.

`public/` is excluded — mixed PHP/HTML view scripts outside the framework produce noise
disproportionate to the value. `tests/` is excluded initially, and including it later requires
the `autoload-dev` entry noted above.

A `phpstan-baseline.neon` freezes whatever pre-existing findings survive at the chosen level, so
CI fails on **new** problems only — exactly the roadmap's "CI should reject new analysis
regressions after the baseline is established".

### Code style — PHP-CS-Fixer

`friendsofphp/php-cs-fixer` as a `require-dev` dependency, configured by `.php-cs-fixer.dist.php`
with the `@PSR12` ruleset plus `declare_strict_types` (which the codebase already satisfies
everywhere, so it is a guard against regression rather than a change).

Chosen over PHP_CodeSniffer because its `--dry-run --diff` mode is the natural CI shape (report
without writing), its rule set is expressed in PHP rather than XML, and its PHP 8.2 support is
better maintained. Both tools could do the job; this is a preference with a reason, not a
correctness argument.

**The one-time reformat is its own commit**, separate from the configuration commit, so the
functional diff of this spec stays readable and `git blame` damage is isolated to one revision.
Scope: `src/`, `bin/`, `tests/`. `public/` is excluded for the same reason as PHPStan.

This respects `CLAUDE.md`'s "don't mass-retrofit old files as a side effect of an unrelated
change" — here the retrofit **is** the change, done deliberately, in its own commit, rather than
leaking into unrelated work.

### CI quality pipeline

`.github/workflows/ci.yml` gains named stages, in the roadmap's order, each with a `name:` that
makes a failed run self-explanatory in the GitHub UI:

```text
composer validate --strict     →  "Validate composer.json"
composer audit                 →  "Audit dependencies for known CVEs"
vendor/bin/phpstan analyse     →  "Static analysis (PHPStan)"
vendor/bin/php-cs-fixer ... --dry-run  →  "Code style (PHP-CS-Fixer)"
vendor/bin/phpunit             →  "Unit tests"
```

The MySQL service, schema load, `.env` write and `bin/migrate` steps are left exactly as they
are — they work, and the fast checks are inserted **before** them so a formatting mistake fails
in seconds instead of after a database spin-up. The integration-test stage the roadmap names is
not added here: no integration tests exist yet, and an empty stage would be decoration.

### Documentation that must change

`CLAUDE.md` currently contains two statements this spec falsifies, and leaving either would make
it actively misleading:

- "**There is still no lint/static-analysis command in this project** (no PHPStan/Psalm/CS-Fixer
  configured). Do not invent one" → replaced with the real commands.
- "`declare(strict_types=1)` is used in newer files but not universally" → already false today
  (measured above), independently of this spec.

## Functional requirements

1. FR1 — `phpstan/phpstan` and `friendsofphp/php-cs-fixer` are added to `require-dev` only, with
   explicit version constraints, and `composer.lock` is updated in the same commit.
2. FR2 — No package is added to `require`; the runtime dependency graph is byte-identical.
3. FR3 — `phpstan.neon` sets the chosen level, includes `src/` and `bin/`, excludes `public/`,
   and references `phpstan-baseline.neon`.
4. FR4 — `vendor/bin/phpstan analyse` exits `0` on the unmodified codebase.
5. FR5 — Introducing a deliberate error PHPStan detects at the chosen level makes it exit non-zero.
6. FR6 — `.php-cs-fixer.dist.php` applies `@PSR12` + `declare_strict_types` over `src/`, `bin/`
   and `tests/`, excluding `public/` and `vendor/`.
7. FR7 — `vendor/bin/php-cs-fixer fix --dry-run --diff` exits `0` after the one-time reformat and
   **never modifies a file** in that mode.
8. FR8 — A deliberately misformatted file makes the same command exit non-zero.
9. FR9 — CI runs the five stages in the order given above, each as a separately named step,
   before the MySQL-dependent steps.
10. FR10 — Each new CI stage fails the job on non-zero exit (no `continue-on-error`).
11. FR11 — `composer.json` gains an `autoload-dev` PSR-4 entry mapping `Tests\` to `tests/`.
12. FR12 — `CLAUDE.md`'s "no lint/static-analysis command" and "strict_types not universal"
    statements are replaced with what is actually true.
13. FR13 — The measured PHPStan error count per level, and the chosen level with its
    justification, are recorded in this spec's Implementation log.

## Non-functional requirements

- **CI duration**: the added stages should not dominate the run. PHPStan on ~49 files is seconds;
  the result cache is not required but may be added if the run is slow.
- **Determinism**: both tools must produce identical results locally and in CI, which is why
  versions are pinned through `composer.lock` rather than floating.
- **No network dependency at analysis time** beyond `composer install` and `composer audit`
  (which necessarily queries the advisory database).

## User flows

Not applicable in the product sense — this spec adds no user-facing behavior. The affected
"user" is a developer: before this spec, pushing a branch runs tests only; after it, the same
push additionally reports a malformed manifest, a vulnerable dependency, a new type error or a
style violation, each as a distinctly named failing stage.

## API changes

Not applicable. No endpoint, request or response is touched.

## Data model and migrations

Not applicable. No schema change; no table is read or written by this spec.

## Architecture and affected components

No application layer changes. Files touched:

- `composer.json` / `composer.lock` — `require-dev` additions, `autoload-dev`.
- `phpstan.neon`, `phpstan-baseline.neon` (new, project root).
- `.php-cs-fixer.dist.php` (new, project root); `.gitignore` gains `.php-cs-fixer.cache`.
- `.github/workflows/ci.yml` — five named stages.
- `CLAUDE.md` — the two false statements above, plus the new commands in "Commands actually
  available".
- `src/`, `bin/`, `tests/` — reformat-only changes, in their own commit, with no logic edits.

## Security considerations

Net positive and the main one worth naming: `composer audit` in CI means a newly disclosed CVE in
any of the 13 runtime dependencies fails the next build instead of sitting unnoticed. Two
cautions: `composer audit` can fail on an advisory with no fixed version available, which would
block unrelated work until triaged — if that happens, the response is to record the exception
deliberately (the `v2.0` verification matrix already says "dependency audit passes **or
exceptions are documented**"), not to drop the stage. And the analysers run over source only;
neither reads `.env` nor any credential.

## Backward compatibility

- **No runtime impact.** Both tools are `require-dev`; a production `composer install
  --no-dev` installs exactly what it installs today.
- **The reformat commit touches many files without changing behavior**, which is why it is kept
  separate and why the PHPUnit suite must pass identically before and after it — that is the
  evidence that it was cosmetic.
- **Existing CI behavior is preserved**: the MySQL service, schema load and migration steps are
  untouched, so a green build still means the same thing it meant before, plus more.
- **A contributor with an older local checkout** needs `composer install` to get the new dev
  tools before the commands work — normal, and documented in `CLAUDE.md`.

## Acceptance criteria

- AC1 — `composer install` succeeds and `composer show --direct` lists `phpstan/phpstan` and
  `friendsofphp/php-cs-fixer`; `composer show --direct --no-dev` lists neither.
- AC2 — `vendor/bin/phpstan analyse` exits `0` with the committed `phpstan.neon` and baseline.
- AC3 — Adding a statement PHPStan flags at the chosen level (e.g. calling an undefined method on
  a typed object) makes `vendor/bin/phpstan analyse` exit non-zero and name that file and line;
  reverting restores exit `0`.
- AC4 — `vendor/bin/php-cs-fixer fix --dry-run --diff` exits `0`, and `git status --short` is
  empty afterwards, proving nothing was written.
- AC5 — Misformatting a file (e.g. collapsing a brace onto the wrong line) makes the same command
  exit non-zero and name that file; reverting restores exit `0`.
- AC6 — `composer validate --strict` exits `0`.
- AC7 — `composer audit` exits `0`, **or** its findings are recorded verbatim in Validation
  evidence with a documented decision, per the Security considerations note.
- AC8 — `docker compose exec web vendor/bin/phpunit` passes with the same test and assertion
  count before and after the reformat commit.
- AC9 — `.github/workflows/ci.yml` contains the five named stages in the roadmap's order, placed
  before the MySQL-dependent steps, none with `continue-on-error`.
- AC10 — A pull request with a deliberate style violation shows the CI job failing at the step
  named for code style, not at an unrelated one. *(Verified on a real PR, or explicitly recorded
  as unverified if no throwaway PR is opened.)*
- AC11 — `CLAUDE.md` no longer claims there is no lint/static-analysis command, no longer claims
  `strict_types` is non-universal, and lists the two new commands.

## Implementation plan

1. **Measure before deciding.** Install both tools (`composer require --dev`), run
   `vendor/bin/phpstan analyse src bin --level N` for N = 0…8, record the error count per level.
   Choose the level by the rule in Proposed behavior. Record everything in the Implementation log.
2. `phpstan.neon` at the chosen level + generate `phpstan-baseline.neon`. Confirm exit `0`.
3. `.php-cs-fixer.dist.php` with `@PSR12` + `declare_strict_types`; add `.php-cs-fixer.cache` to
   `.gitignore`. Run `--dry-run` and record how large the pending diff is.
4. **Separate commit**: run the fixer for real over `src/`, `bin/`, `tests/`. Run PHPUnit before
   and after and compare counts.
5. `composer.json` `autoload-dev` for `Tests\`; `composer dump-autoload`.
6. Wire the five stages into `.github/workflows/ci.yml`.
7. Update `CLAUDE.md` (both false statements + the new commands).
8. Run the full local sequence end to end and fill in Validation evidence.

## Testing and validation strategy

**Correction to the `/spec-plan` skill's own instructions:** they direct the author to state that
this project has no automated test infrastructure, citing `specs/000-project-baseline.md`. That
is stale — the baseline was corrected on 2026-09-03 (`specs/000-project-baseline.md:143`) and
PHPUnit plus GitHub Actions CI have existed since specs 004/005. This is the second spec to hit
that stale instruction (spec 033 recorded the same correction); the skill file itself is worth
fixing in a later pass.

- **The tools validate themselves**: AC2–AC7 are exit codes of real commands, run locally and
  pasted verbatim into Validation evidence. No mocking involved.
- **The deliberate-failure criteria (AC3, AC5) are the important ones** — a check that passes
  proves nothing if it would also pass on broken input. Both must be exercised by actually
  breaking a file and reverting it.
- **AC8 is the guard on the reformat**: identical test and assertion counts before and after is
  the evidence that the reformat was cosmetic.
- **AC10 needs a real pull request** to observe CI stage naming. If no throwaway PR is opened, it
  must be recorded as unverified rather than inferred from the YAML.
- Docker must be running; it was unavailable while this spec was drafted.

## Rollout and rollback

Rollout: merge; the next push or PR runs the new stages. Nothing to deploy — no runtime artifact
changes.

Rollback: revert the commits. Because the tools are `require-dev` and the CI stages are additive,
reverting restores the previous pipeline exactly. The reformat commit can be reverted
independently of the configuration commit, which is a second reason to keep them separate.

## Open questions

None blocking.

Non-blocking, to be resolved by measurement during implementation:

- **The PHPStan level.** Deliberately undecided; step 1 decides it by the stated rule. This is
  recorded as a question rather than guessed at, because asserting a level without running the
  tool would be exactly the kind of unverified claim `CLAUDE.md` forbids.
- **Whether `composer audit` is clean today.** Could not be checked while drafting (Docker down).
  If it reports an advisory with no fix available, AC7's documented-exception path applies.
- **How large the reformat diff is.** Unknown until step 3. If it turns out to be enormous,
  narrowing the initial ruleset below `@PSR12` is preferable to a 200-file commit; that decision
  belongs to implementation, with the outcome recorded.

## Task checklist

- [x] 1. Measure PHPStan levels 0–8 and choose one by the stated rule → **level 5**
- [x] 2. `phpstan.neon` + `phpstan-baseline.neon`, exit 0
- [x] 3. `.php-cs-fixer.dist.php` + `.gitignore` entry (already present), measured the pending diff
- [x] 4. One-time reformat as its own commit, PHPUnit counts compared before/after
- [x] 5. `autoload-dev` for `Tests\`
- [x] 6. Five named CI stages in `ci.yml`
- [x] 7. `CLAUDE.md` corrections + new commands
- [x] 8. Validation evidence filled from real command output

Added beyond the original plan, each logged below: `docker-compose.yml` volume mounts (2),
`.gitattributes` (5), and three `composer` scripts (6).

## Implementation log

- **2026-09-23 — 1. PHPStan level measured, then chosen.** Error counts for `src` + `bin`:
  level 0 → **0**, 1 → 67, 2 → 103, 3 → 103, 4 → 104, 5 → **107**, 6 → 215, 7 → 293, 8 → 293.
  **Level 5 chosen.** The jump from 107 to 215 at level 6 is the untyped model methods and
  missing parameter/return types — fixing those is an explicit non-goal of this spec, and a
  200-entry baseline would be a maintenance liability. Level 5 keeps every check below that
  line. *(First measurement pass was wrong: it counted the Docker Compose warning that goes to
  stderr, reporting "1 error" at level 0. Recounted with the streams separated; level 0 is
  clean. Recorded because the first number was stated out loud before being checked.)*
- **2026-09-23 — 2. `docker-compose.yml` needed new volume mounts — not anticipated by the
  spec.** `phpstan analyse` failed with "At least one path must be specified to analyse", because
  the compose file mounts **named paths only** (`./src`, `./bin`, `./composer.json`, …) and never
  the project root, so a new root-level `phpstan.neon` simply did not exist inside the container.
  Added mounts for `phpstan.neon`, `phpstan-baseline.neon` and `.php-cs-fixer.dist.php` on the
  `web` service, and recreated it. The earlier level measurements were unaffected because they
  passed `src bin` explicitly on the command line.
- **2026-09-23 — 3. `path: ../src` in `phpstan.neon` was wrong.** Neon paths resolve relative to
  the config file, so it became `/var/www/src` and PHPStan refused to start. Removed the
  constraint entirely — the `message` regex is already scoped to `App\Models\`, so the path added
  nothing.
- **2026-09-23 — 4. Ignore patterns collapsed 107 findings to 16.** All 67 level-1 errors were a
  single systematic cause — Eloquent's `Model::__callStatic()` (`where`, `create`, `findOrFail`,
  `transaction`, …) — and most of the level-5 additions were `Model::__get()` attribute access.
  Two scoped `ignoreErrors` regexes handle both, which is far better than 67 brittle baseline
  entries. The remaining 16 went into `phpstan-baseline.neon` (15 entries; one has `count: 2`).
- **2026-09-23 — 5. The baselined findings were inspected, not just frozen.** Three looked like
  real defects and were checked against the source before being dismissed:
  - `PrintService.php:44` — "Parameter #2 `$port` / #3 `$timeout` expects string, int given".
    **Not a GastroFlow bug.** `NetworkPrintConnector::__construct($ip, $port = "9100", $timeout =
    false)` in `vendor/mike42/escpos-php` is **untyped**, carrying a `@param string` docblock that
    contradicts its own `false` default. PHPStan is reporting an inaccurate third-party docblock.
    This was initially reported to the owner as a probable real bug and is corrected here.
  - `OrderRepository.php:408` — "Call to an undefined method `Query\Builder::firstOrFail()`".
    False positive: PHPStan resolves the `->lockForUpdate()` chain to the query builder rather
    than the Eloquent builder, where the method does exist.
  - `OrderService.php:13` — "`$printService` is never read, only written". **A genuine finding.**
    `OrderService` dispatches printing through `JobService`, so the injected `PrintService` is
    dead. Left in place deliberately: removing it changes a constructor signature, which is
    "rewriting code to satisfy the analyser" — an explicit non-goal. Worth its own small spec.
- **2026-09-23 — 6. The CRLF trap, and why `.gitattributes` was added.** The first
  `php-cs-fixer --dry-run` reported **"69 of 69 files"**, which looked like catastrophic style
  debt. It was not: the working tree is CRLF (`core.autocrlf=true`, no `.gitattributes`), and
  PSR-12's `line_ending` rule normalises to LF. Measured the real debt by copying `src`, `tests`
  and `bin` inside the container, stripping CR, and re-running: **28 of 69**. After applying the
  fix for real, `git diff` confirmed **29 files** with content changes, of which only **14** differ
  beyond whitespace — all of them brace expansion (`if (...) return false;` → braced block) and a
  blank line after `<?php`. Added `.gitattributes` pinning `eol=lf` so this does not recur: without
  it the local check reports 69 files while CI reports 0, and the two contradict each other.
- **2026-09-23 — 7. `composer` scripts added.** `analyse`, `style` and `style:fix` in
  `composer.json`. Not in the spec's plan; added because `CLAUDE.md` documents the long
  `docker compose exec …` invocations and short aliases reduce the chance of the two drifting.
- **2026-09-23 — 8. One cosmetic regression accepted.** In `OrderValidator.php` the fixer moved a
  trailing comment (`// must be an integer value`) to after the closing brace of the block it
  described. Semantically irrelevant, left as the fixer produced it rather than hand-editing
  against the tool.

## Validation evidence

All commands run inside the `web` container on 2026-09-23.

- **AC1** — `composer show --direct` lists `friendsofphp/php-cs-fixer 3.95.27`,
  `phpstan/phpstan 2.2.15`, `phpunit/phpunit 11.5.56`.
  `composer show --direct --no-dev | grep -cE "phpstan|cs-fixer|phpunit"` → `0`.
- **AC2** — `vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`, `EXIT=0`.
- **AC3** — injected a broken construct into `src/Services/PricingService.php`; PHPStan →
  `[ERROR] Found 10 errors`, `EXIT=1`, naming `PricingService::lineTotal()` and
  `::orderTotal()` as undefined. `git checkout -- src/Services/PricingService.php` restored
  `[OK] No errors`, `EXIT=0`.
- **AC4** — `vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php` →
  `Found 0 of 69 files that can be fixed`, `EXIT=0`; `git diff --numstat -- src/Money.php | wc -l`
  → `0`, confirming nothing was written.
- **AC5** — appended a misformatted class to `src/Money.php`; same command →
  `1) src/Money.php`, `Found 1 of 69 files`, `EXIT=8`. `git checkout -- src/Money.php` restored
  `Found 0 of 69`, `EXIT=0`.
- **AC6** — `composer validate --strict` → `./composer.json is valid`, `VALIDATE_EXIT=0`.
- **AC7** — `composer audit` → `No security vulnerability advisories found.`, `AUDIT_EXIT=0`.
  No documented exception needed.
- **AC8** — `vendor/bin/phpunit` **before** the reformat → `OK (133 tests, 231 assertions)`;
  **after** → `OK (133 tests, 231 assertions)`. Identical, which is the evidence that the
  reformat was cosmetic.
- **AC9** — `.github/workflows/ci.yml` contains, in order: "Validate composer.json", "Audit
  dependencies for known vulnerabilities", "Static analysis (PHPStan)", "Code style
  (PHP-CS-Fixer)", then the MySQL/schema/migrate steps, then "Unit tests (PHPUnit)". None carries
  `continue-on-error`. Verified by reading the file.
- **AC11** — `grep -c "There is still no lint" CLAUDE.md` → `0`;
  `grep -c "not universally" CLAUDE.md` → `0`. Both replaced with the real commands and the
  measured strict_types state.

**Not validated:**

- **AC10 — unverified.** Observing that a deliberate style violation fails *the step named for
  code style* in a real GitHub Actions run requires pushing a throwaway commit to a pull request.
  That was not done. The stage ordering and naming are verified statically (AC9), but the
  end-to-end CI behaviour on a real PR is asserted from the YAML, not observed. The PR that
  merges this spec will exercise the stages on a passing run, which is weaker evidence than a
  deliberate failure.
- **The level-6 cost is recorded but not attempted.** 215 findings at level 6 vs 107 at level 5;
  no work was done to see how far annotations would actually reduce that.
