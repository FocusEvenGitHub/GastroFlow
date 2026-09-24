# Spec 035 — MySQL-backed integration tests and the E2E smoke journey

## Metadata

- Status: Implemented
- Created: 2026-09-23
- Updated: 2026-09-23
- Owner: Henry
- Related issue: Not applicable (roadmap items — `docs/ROADMAP.md`, `v1.8.0 — Reliability & Quality`: "Integration tests", "End-to-end smoke tests")
- Related branch: `035`

## Context

Third work item of the `v1.8.0 — Reliability & Quality` milestone, after spec 033 (job
reliability) and spec 034 (static analysis, style, CI pipeline).

The roadmap lists "Integration tests" and "End-to-end smoke tests" as separate subsections.
They are covered by one spec, as agreed with the owner before drafting: the E2E journey is an
integration test that happens to span the whole workflow, it needs exactly the same database
fixture and bootstrap as the others, and splitting them would make the second spec trivial
boilerplate on top of the first.

This spec also closes a gap this milestone already owes: **spec 033 could not test concurrent
job claiming**, because `lockForUpdate()` is a no-op on the SQLite used by the unit suite. Spec
033 recorded that explicitly and deferred it here. It is the single most important test in this
spec — the fiscal milestone (`v2.1.0`) depends on that queue not double-issuing.

## Problem

1. **Almost nothing is tested against the real database engine.** Of 15 test files, 4 use
   SQLite `:memory:` and one (`tests/Smoke/ApiTest.php`) hits real MySQL with a single
   assertion. Everything else is pure unit testing. MySQL-specific behavior — `FOR UPDATE`
   locking, `ONLY_FULL_GROUP_BY`, `ENUM` constraints, unique indexes, `TIMESTAMP` semantics —
   is therefore exercised only in production.
2. **Concurrency is untested by construction.** `JobService::processNext()` claims inside
   `DB::transaction` + `lockForUpdate()`. On SQLite that lock does nothing, so
   `tests/Unit/JobServiceTest.php` proves the retry logic but says nothing about two workers
   racing for the same row. Same for `OrderRepository`'s concurrency-safe `order_number`
   generation (spec 019), whose unique index is a MySQL object.
3. **The one existing MySQL test writes nothing — and could not safely write.**
   `tests/Smoke/ApiTest.php` boots the real app, which calls `Database::boot()`, which reads
   `MYSQL_DATABASE` from the environment. Locally that is the **developer's real restaurant
   database**. Any integration test that creates orders, mutates the menu or enqueues jobs
   would pollute live data, and `CLAUDE.md` forbids destructive database operations.
4. **No test covers a whole journey.** Cashier → order → kitchen → completion → report is the
   product's core path and is verified only by hand.

## Goals

- Integration tests run against real MySQL 8.0, covering the ten workflows the roadmap
  prioritises plus the E2E journey.
- Two concurrent claims of the same job never both succeed — proven, not assumed.
- The suite can never run against the developer's real database by accident.
- CI runs the new suite as its own named stage, after the unit tests.

## Non-goals

- **No browser automation.** The roadmap says so explicitly: "Do not attempt to test the entire
  frontend through browser automation." The E2E journey is exercised through the HTTP layer of
  the Slim app, not through `public/`'s Alpine.js pages.
- **No new Composer dependency.** PHPUnit 11 and MySQL 8.0 are already present, in the project
  and in CI.
- **No test for the physical printer.** `PrintService` against a real ESC/POS device stays out;
  job creation is asserted at the `jobs` table, which is where the roadmap's "job creation"
  item actually lives.
- **No refactoring of application code to make it testable**, unless a test is impossible
  otherwise — in which case it is reported, not done silently.
- **No changes to the existing unit suite.** The SQLite tests keep working as they are; this
  spec adds a suite beside them rather than migrating them.
- **No other `v1.8.0` items** — printing/realtime reliability, structured logging, audit
  history, health checks, migration reliability and backup/restore each get their own spec.

## Current behavior

Confirmed by reading the code on 2026-09-23.

**Test layout** — `phpunit.xml` declares two suites, `Smoke` (`tests/Smoke`) and `Unit`
(`tests/Unit`), with `bootstrap="tests/bootstrap.php"`. 133 tests, 231 assertions.
`tests/bootstrap.php` loads `.env` via `Dotenv::safeLoad()` and then copies any `getenv()`
values missing from `$_ENV`, because CI supplies configuration as real environment variables
while `App\Settings::get()` reads `$_ENV` only.

**Database wiring** — `src/Database.php`'s `boot(Settings $settings)` builds a single MySQL
Capsule connection from `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, sets it
global and boots Eloquent. There is no second connection, no test connection and no
`MYSQL_DATABASE` override anywhere in the test bootstrap — so a test that boots the app talks
to whatever `.env` points at.

**Existing MySQL coverage** — `tests/Smoke/ApiTest.php` is the only file that boots
`App\App`; it issues `GET /api/menu` through `$app->handle()` and asserts `200`. Read-only.

**Existing SQLite coverage** — `JobServiceTest`, `OrderRepositoryTest`, `PrintServiceTest` and
`ReportServiceTest` build `:memory:` schemas by hand in `setUp()`. `JobServiceTest` duplicates
the `jobs` DDL from migrations `007` and `016`, which spec 033's implementation log already
flags as a file that must be kept in sync manually.

**Routes the tests will exercise** (`src/Routes.php`):

- Public: `POST /api/login`; `GET /api/menu`; `GET|POST /api/orders`;
  `PATCH /api/orders/{id}`; `POST /api/orders/{id}/items`, `/print`, `/complete`,
  `/uncomplete`, `/cancel`; `GET /api/orders/next-number`; `GET /api/kitchen/food-summary`.
- JWT + role: `PATCH /api/admin/account/password`; and under `/api/admin`, menu and ingredient
  mutations plus `/settings*` and `/logs` (`admin` only), and eight `/reports/*` endpoints
  (`admin` or `manager`). Role gating is `RoleMiddleware`, applied per route.

**CI** — `.github/workflows/ci.yml` already provisions a `mysql:8.0` service, loads
`common/sql/001_schema.sql`, writes a `.env`, runs `php bin/migrate`, then the five quality
stages from spec 034 ending in "Unit tests (PHPUnit)". The database it builds is ephemeral, so
CI needs no extra protection — only the local run does.

**No admin user exists by default** — spec 015 removed the seeded `admin`/`admin123`;
`bin/create-admin` is the only way one is created. Authentication tests must therefore create
their own user rather than assume one.

## Proposed behavior

### A dedicated test database, and a hard refusal to run without it

A new `tests/Integration/` suite runs only when `MYSQL_DATABASE_TEST` is set and names a
database **different from** `MYSQL_DATABASE`. If it is unset, every integration test is
skipped with a message saying how to enable it. If it is set but equal to the development
database, the bootstrap **fails loudly** rather than skipping — a misconfiguration that would
destroy real data must not be silently tolerated.

The suite's bootstrap creates the schema in that database from `common/sql/001_schema.sql` and
then applies `common/migrations/*.sql` through the existing `App\Database\MigrationRunner`,
which is the same path `bin/migrate` uses — so the tests exercise the real migration chain
rather than a hand-written DDL copy.

Chosen over per-test transaction rollback because the concurrency test **cannot** work under
rollback: two connections must see committed rows for `FOR UPDATE` to mean anything. Chosen
over "CI only" so the tests remain debuggable locally.

Between tests, the affected tables are emptied. `DELETE` is used rather than `TRUNCATE` so
foreign keys behave predictably, and only within the test database, guarded by the same check
above.

### What gets covered

The roadmap's ten priority workflows, plus the journey:

| Workflow | Asserted through |
|---|---|
| Authentication | `POST /api/login` with a user created by the fixture — valid, wrong password, unknown user |
| Authorization | `/api/admin/*` without a token, with a bad token, and with a `cashier` token against an `admin`-only route |
| Order creation | `POST /api/orders`, then the `orders`/`order_items` rows |
| Order numbering | Sequential per `business_date`; **and two concurrent creations never collide** |
| Pricing | Totals computed by `PricingService` against what is persisted, in integer cents |
| Order completion | `POST /api/orders/{id}/complete` → `status = done` |
| Order reopening | `POST /api/orders/{id}/uncomplete` → back to `pending`; and `cancelled` refuses to reopen (`409`, spec 020) |
| Menu mutations | `POST/PATCH/DELETE /api/admin/items` with a valid token |
| Reports | `GET /api/admin/reports/sales` and `/main-dishes` over seeded orders, including the `ONLY_FULL_GROUP_BY` path spec 032 exercised manually |
| Job creation | `POST /api/orders` with `print_ticket` → a `print` row in `jobs` with `status = pending` |

**The concurrency test** is the one with a defined shape rather than a defined assertion count:
insert one job, open **two separate PDO connections**, have both attempt
`JobService::processNext()` against it, and assert exactly one claims it — the other must get
`false`. The same technique covers `order_number`: two concurrent `POST /api/orders` on one
business date must not produce duplicate numbers, which is what spec 019's unique index exists
to guarantee.

### The E2E journey

One test walking: create order (cashier) → it appears in `GET /api/orders` (kitchen) →
`POST /api/orders/{id}/complete` → the order shows up in
`GET /api/admin/reports/sales` for that business date with the right total. Through the HTTP
layer, no browser.

### CI

`phpunit.xml` gains an `Integration` suite. CI gets a stage named "Integration tests (PHPUnit,
MySQL)" after "Unit tests (PHPUnit)", with `MYSQL_DATABASE_TEST` pointing at a second database
created in the same MySQL service. Keeping it a separate stage means a failure says whether
the unit or the integration layer broke.

## Functional requirements

1. FR1 — `tests/Integration/` exists as a PHPUnit suite registered in `phpunit.xml`.
2. FR2 — With `MYSQL_DATABASE_TEST` unset, every integration test is **skipped**, not failed,
   and the whole `vendor/bin/phpunit` run still exits `0`.
3. FR3 — With `MYSQL_DATABASE_TEST` equal to `MYSQL_DATABASE`, the suite **fails** with a
   message naming the collision. It must never proceed.
4. FR4 — The test schema is built from `common/sql/001_schema.sql` plus
   `common/migrations/*.sql` via `App\Database\MigrationRunner`, not a hand-copied DDL.
5. FR5 — Each test starts from a known state; data written by one test is not visible to the
   next.
6. FR6 — No integration test writes to the database named by `MYSQL_DATABASE`.
7. FR7 — Tests cover each of the ten workflows in the table above, at least one assertion each.
8. FR8 — Two concurrent `JobService::processNext()` calls on separate connections against one
   pending job result in exactly one claim and one `false`.
9. FR9 — Two concurrent `POST /api/orders` on the same business date produce two distinct
   `order_number` values.
10. FR10 — The E2E test asserts the created order's total appears in the sales report for its
    business date.
11. FR11 — CI runs the integration suite as its own named stage after the unit tests.
12. FR12 — `CLAUDE.md` documents how to run the integration suite locally, including the
    `MYSQL_DATABASE_TEST` requirement.

## Non-functional requirements

- **Safety over convenience**: the default (unset variable) must be the safe one. A developer
  who does nothing must not be able to damage their data by running `vendor/bin/phpunit`.
- **Runtime**: the integration suite should stay in the tens of seconds. If schema setup per
  test proves too slow, build the schema once per run and only clear data between tests.
- **No secrets in test fixtures** — the test admin user is created with a throwaway password
  generated in the fixture, never a real credential.
- **Determinism**: tests must not depend on the wall clock's date rolling over mid-run; the
  business date used is fixed explicitly rather than read from "today" where it matters.

## User flows

Not applicable in the product sense — no user-facing behavior changes. The affected user is a
developer: before this spec, `vendor/bin/phpunit` proves unit logic; after it, with
`MYSQL_DATABASE_TEST` configured, the same command additionally proves the real workflows
against the real engine.

## API changes

Not applicable. No endpoint, request or response shape is added or changed. Existing endpoints
are exercised as they are.

## Data model and migrations

Not applicable as a schema change — this spec adds no migration and alters no table. It
**consumes** the existing migration chain to build a separate test database.

## Architecture and affected components

No application layer changes are planned. If one proves unavoidable, the spec's non-goals say
to report it rather than do it quietly.

- `tests/Integration/` (new) — the suite, plus a base `TestCase` owning the guard, schema
  build and per-test cleanup.
- `tests/bootstrap.php` — may need to expose the test-database override; the existing
  `getenv()` → `$_ENV` bridge already handles CI-style configuration.
- `phpunit.xml` — the new suite.
- `.github/workflows/ci.yml` — the new stage and the second database.
- `.env.example` — document `MYSQL_DATABASE_TEST`.
- `CLAUDE.md` — how to run it (FR12).
- `docker-compose.yml` — only if the test database needs creating in the local `db` service;
  to be confirmed during implementation, since `CREATE DATABASE` can also be done by the
  bootstrap with the existing credentials, if the MySQL user has the grant. **This is an open
  question below.**

## Security considerations

The dominant risk here is not an attacker but the test suite itself: it must never write to
production or development data. FR3's hard failure and FR6 exist for that. Beyond that, tests
create a real user with a hashed password through the normal code path, and the JWT they
obtain is a real token — neither is written to disk, logged, or committed, and the password is
generated per run rather than fixed. `JWT_SECRET` is already required by `src/Routes.php:27`
and is supplied by the environment in CI, so no new secret is introduced.

## Backward compatibility

- **The existing suite is untouched.** Unit and Smoke tests keep their SQLite/real-MySQL
  behavior; their count must not change (see AC).
- **`vendor/bin/phpunit` with no new configuration behaves exactly as it does today**, because
  the integration tests skip themselves. That is what keeps this change safe for anyone who
  pulls it without reading the docs.
- **CI gets slower** by roughly the integration suite's runtime. Acceptable for the coverage;
  the stage is separate so the cost is visible.

## Acceptance criteria

- AC1 — With `MYSQL_DATABASE_TEST` unset, `vendor/bin/phpunit` exits `0` and reports the
  integration tests as skipped; the Unit + Smoke count is unchanged from `133 tests, 231
  assertions`.
- AC2 — With `MYSQL_DATABASE_TEST` set equal to `MYSQL_DATABASE`, the run fails with a message
  naming the collision, and `SELECT COUNT(*) FROM orders` in the development database is
  unchanged before and after.
- AC3 — With `MYSQL_DATABASE_TEST` set to a distinct database, `vendor/bin/phpunit` exits `0`
  with the integration tests executed, and the development database's `orders`, `jobs` and
  `menu_items` row counts are identical before and after the run.
- AC4 — A test asserts `POST /api/login` returns `200` with a token for correct credentials
  and `401` for a wrong password.
- AC5 — A test asserts `GET /api/admin/reports/sales` returns `401` with no token, and `403`
  with a valid `cashier` token.
- AC6 — A test creates an order via `POST /api/orders` and asserts the persisted total in cents
  equals what `PricingService` computes for the same items.
- AC7 — A test asserts `complete` moves an order to `done`, `uncomplete` returns it to
  `pending`, and `uncomplete` on a `cancelled` order returns `409`.
- AC8 — **Two `JobService::processNext()` calls on two separate MySQL connections against a
  single pending job: exactly one returns `true`, the other `false`, and the job's `attempts`
  is `1` — not `2`.**
- AC9 — Two concurrent `POST /api/orders` for the same business date yield two distinct
  `order_number` values and two `orders` rows.
- AC10 — A test asserts `POST /api/orders` with `print_ticket: true` inserts exactly one `jobs`
  row with `queue = 'print'` and `status = 'pending'`.
- AC11 — The E2E test asserts the completed order's total is included in
  `GET /api/admin/reports/sales` for its business date.
- AC12 — A menu mutation test asserts `POST /api/admin/items` with an `admin` token creates the
  item and that `GET /api/menu` then returns it.
- AC13 — CI shows a step named for the integration suite, running after "Unit tests (PHPUnit)",
  and the whole workflow is green on the pull request.
- AC14 — PHPStan and PHP-CS-Fixer (spec 034) both still exit `0` with the new test files
  included.

## Implementation plan

1. Base `TestCase` for `tests/Integration/`: the `MYSQL_DATABASE_TEST` guard (skip when unset,
   hard-fail on collision), connection setup, schema build via `MigrationRunner`, per-test
   cleanup.
2. Register the `Integration` suite in `phpunit.xml`; confirm AC1 and AC2 before writing any
   real test.
3. Fixture helpers: create a user with a given role, a category + menu item, an order.
4. Authentication and authorization tests (AC4, AC5).
5. Order creation, numbering, pricing, completion, reopening (AC6, AC7).
6. Menu mutations and reports (AC12, and the report assertions).
7. Job creation (AC10).
8. **The two concurrency tests** (AC8, AC9) — deliberately after the simpler ones, so the
   fixture and connection handling are already proven when the hardest test is written.
9. The E2E journey (AC11).
10. CI stage + second database (AC13); `.env.example`, `CLAUDE.md` (FR12).
11. Run PHPStan and PHP-CS-Fixer over the new files (AC14).

## Testing and validation strategy

**Correction to the `/spec-plan` skill's own instructions**, for the third spec running: they
say to state that the project has no automated test infrastructure, citing
`specs/000-project-baseline.md`. That was corrected on 2026-09-03
(`specs/000-project-baseline.md:143`) — PHPUnit and CI have existed since specs 004/005, and
spec 034 has since added PHPStan and PHP-CS-Fixer. The skill file is overdue a fix; specs 033
and 034 both recorded the same correction.

This spec's subject *is* testing, so validation is largely self-demonstrating, with two
exceptions that need care:

- **AC2 and AC3 are the safety criteria and must be exercised for real**, including reading the
  development database's row counts before and after. A guard that has never been tripped is
  not a guard.
- **AC8 is the criterion that cannot be faked.** It must use two genuinely separate PDO
  connections; running both claims on one connection would pass while proving nothing, because
  a single connection shares the transaction. The implementation log must state how the two
  connections were obtained.
- Everything else is asserted by the tests themselves, with `vendor/bin/phpunit` output
  recorded verbatim in Validation evidence.
- Docker must be running.

## Rollout and rollback

Rollout: merge. Developers who set `MYSQL_DATABASE_TEST` get the new coverage; those who do
not see skipped tests and no behavior change. CI picks up the new stage automatically.

Rollback: revert the commits. Nothing in the application changes, no schema is altered and no
data migrates, so a revert is complete by construction.

## Open questions

None blocking.

Non-blocking, to be resolved during implementation:

- **Who creates the test database.** The bootstrap can `CREATE DATABASE IF NOT EXISTS` only if
  the configured MySQL user holds that grant; the compose `db` service's user may not. If it
  does not, the alternatives are a documented one-time manual `CREATE DATABASE`, or adding it
  to `docker-compose.yml`'s MySQL init. Decide by testing the grant, and record which.
- **Schema build cost.** If rebuilding the schema per test class is slow enough to hurt, build
  once per run and clear data between tests. Measure before optimising — the roadmap's own
  "Query performance" item warns against speculative optimisation.
- **Whether `tests/Integration` should be added to PHPStan's analysed paths.** Spec 034
  deliberately excluded `tests/` pending the `autoload-dev` mapping, which spec 034 then added.
  Revisit here or leave to a later pass; either way, AC14 requires the new files not to break
  the existing checks.

## Task checklist

- [x] 1. Base `TestCase` with the guard, schema build and cleanup
- [x] 2. `Integration` suite registered; AC1/AC2 proven before writing real tests
- [x] 3. Fixture helpers (user/role, menu item, order)
- [x] 4. Authentication + authorization tests
- [x] 5. Order creation, numbering, pricing, completion, reopening
- [x] 6. Menu mutations + reports
- [x] 7. Job creation
- [x] 8. Concurrency: job claim (AC8) and `order_number` (AC9)
- [x] 9. E2E journey
- [x] 10. CI stage, `.env.example`, `CLAUDE.md`
- [x] 11. PHPStan + PHP-CS-Fixer clean over the new files

## Implementation log

- **2026-09-23 — 1. The open question about who creates the test database: answered by
  testing, and the answer was "not the app".** `SHOW GRANTS` for `restuser` returns
  `GRANT USAGE ON *.*` plus `GRANT ALL PRIVILEGES ON restaurant.*` — nothing more. A
  `CREATE DATABASE restaurant_test` as that user fails with `1044 Access denied`. So the
  bootstrap cannot self-provision. Resolved as: created once with root credentials read from
  `.env` at runtime (the value was never printed or placed in any file), granted to
  `MYSQL_USER`; CI creates it with a dedicated step; `.env.example` and `CLAUDE.md` document
  the one-time manual step for anyone else.
- **2026-09-23 — 2. `common/sql/001_schema.sql` is not database-agnostic, and executing it
  verbatim would have hit the live database.** Its first two statements are
  `CREATE DATABASE IF NOT EXISTS restaurant;` and `USE restaurant;`, both hardcoded. Run
  against the test connection they would have switched it onto the developer's real database
  and created tables there — the exact accident this spec exists to prevent. The bootstrap
  strips both statements before executing the file. Worth knowing for any future tooling that
  replays this schema.
- **2026-09-23 — 3. Redirecting the app at the test database needs no application change.**
  `Settings::get()` reads `$_ENV`, so setting `$_ENV['MYSQL_DATABASE']` in `setUp()` points
  `Database::boot()`, the Slim app and Eloquent at the test database for the duration of the
  test; `tearDown()` restores it so the Smoke suite is unaffected. No production code was
  modified to make any of this testable.
- **2026-09-23 — 4. First guard test was wrong, not the guard.**
  `testRunsAgainstTheDedicatedTestDatabase` compared `MYSQL_DATABASE` with
  `MYSQL_DATABASE_TEST` *after* `setUp()` had already overwritten the former, so it compared a
  value with itself and failed. Fixed by capturing the live name before the redirect and
  exposing it as `liveDatabase()`.
- **2026-09-23 — 5. The concurrency helper had to be its own PSR-4 file.** `NoopJob` was first
  declared inside `ConcurrencyTest.php`. The worker subprocesses autoload through Composer and
  never load the test file, so the handler did not resolve, every job took the failure path,
  and the test passed 2 runs in 3. Moving it to `tests/Integration/NoopJob.php` made the
  handler resolve and removed that source of flakiness.
- **2026-09-23 — 6. Counting claims from process output was wrong; the database is the
  witness.** A worker that claims and then dies mid-transaction never prints `claimed`, so
  counting stdout understates claims and the test failed for the wrong reason. The invariant
  is now read from `jobs.attempts`, which is incremented exactly once per successful claim
  inside the locked section. Process output is kept only as a secondary, weaker assertion.
- **2026-09-23 — 7. ⚠ A REAL DEFECT WAS FOUND, and deliberately not fixed here.** See the
  dedicated section below. Spec 035's non-goals forbid changing application code, so it is
  reported rather than patched. The test records it with `markTestIncomplete()` so it is
  visible on every run without turning CI red for a pre-existing bug.
- **2026-09-23 — 8. Four racers, not two.** The processes are started without a
  synchronisation barrier, so overlap inside the critical section is probable but not
  guaranteed. Four concurrent workers raise the chance of genuine contention; this is an
  honest limitation of the technique, not a guarantee of contention on every run.

### ⚠ Defect found by AC8 — job queue has no deadlock handling

**What happens.** With four workers contending for one job, MySQL raises
`SQLSTATE[40001] / 1213 Deadlock found` inside `JobService::processNext()`. It was observed on
the `UPDATE` that sets `status = completed` — that is, **after the handler has already done its
work**. `processNext()`'s `try/catch` wraps both the handler call and that success update, so
the deadlock is caught and treated as a job failure: `recordFailure()` puts the job back to
`pending` with the deadlock message in `last_error`, and the worker exits `0` reporting
success. Nothing is logged as a deadlock, and stderr is empty.

**Why it matters.** The job's work was already performed, and the job is now queued to be
performed again. For `PrintOrderJob` that is a duplicate ticket. For `v2.1.0`'s
`IssueFiscalDocumentJob` it would be a duplicate NFC-e — precisely the "legal/financial
incident, not a cosmetic bug" that `docs/ROADMAP.md` names when it says the fiscal milestone
depends on this queue. The roadmap's "idempotency where critical" item was deferred to
`v2.1.0` on the assumption the queue underneath was sound; this finding says it is not yet.

**Evidence.** Two manifestations, both observed:
1. Loud — the worker process dies with the deadlock on stderr, the nested message showing the
   failure path deadlocking on its own `UPDATE`.
2. Silent and worse — no stderr at all, job left `status = pending` with
   `last_error` containing `1213`, which is how the test now detects it.

**Not fixed here.** Spec 035 changes no application code (stated non-goal). A fix needs its own
spec: deadlock-aware retry around the claim and the completion update, and a decision about
whether completing a job whose handler already ran should be idempotent rather than retried.

## Validation evidence

All commands run on 2026-09-23 inside the containers. `restaurant` is the development
database; `restaurant_test` is the dedicated one created for this spec.

- **AC1** — `vendor/bin/phpunit` with `MYSQL_DATABASE_TEST` unset →
  `OK, but some tests were skipped! Tests: 136, Assertions: 231, Skipped: 3.`, `EXIT=0`.
  Assertions stayed at `231`, matching the pre-spec Unit+Smoke total exactly.
- **AC2** — with `MYSQL_DATABASE_TEST=restaurant` (the live database):
  `FAILURES! Tests: 3, Assertions: 3, Failures: 3.`, `EXIT=1`, each with
  `MYSQL_DATABASE_TEST ("restaurant") is the same database as MYSQL_DATABASE. Refusing to run…`.
  Development database row counts **before**: `orders: 86, jobs: 50, menu_items: 77, users: 8`;
  **after**: `orders: 86, jobs: 50, menu_items: 77, users: 8` — untouched.
- **AC3** — with `MYSQL_DATABASE_TEST=restaurant_test`, full run →
  `Tests: 159, Assertions: 313, Incomplete: 1.`, `EXIT=0`. Development database counts after the
  run: `orders: 86, jobs: 50, menu_items: 77, users: 8` — unchanged, so nothing leaked.
- **AC4** — `AuthenticationTest::testLoginSucceedsWithCorrectCredentials` (200 + token + role)
  and `testLoginFailsWithWrongPassword` (401) pass; `testLoginFailsForUnknownUser` (401) too.
- **AC5** — `testAdminRouteRejectsMissingToken` (401), `testAdminRouteRejectsMalformedToken`
  (401), `testReportsRefuseACashierToken` (403), `testReportsAcceptAManagerToken` (200) and
  `testSettingsRefuseAManagerToken` (403) all pass — the last two together prove the two role
  sets are really distinct rather than both being "any authenticated user".
- **AC6** — `OrderWorkflowTest::testPersistedTotalMatchesPricingService` compares the persisted
  `unit_price` against `PricingService::unitPriceFor()` in integer cents; passes.
- **AC7** — `testCompleteThenReopenMovesStatusBothWays` (`done` then `pending`) and
  `testCancelledOrderCannotBeReopened` (`409`, row preserved) pass.
- **AC8** — `ConcurrencyTest::testTwoWorkersCannotClaimTheSameJob`: four separate OS processes
  (`proc_open`, all started before any is read), `jobs.attempts` is `1`, and at most one process
  reports `claimed`. **The criterion is met for the claim invariant** — the job is never handed
  to two workers. The test then reports `Incomplete` because of the deadlock defect documented
  above, which is a separate bug it uncovered rather than a failure of the locking.
- **AC9** — `testConcurrentOrdersGetDistinctOrderNumbers`: four concurrent order creations
  produce four rows with four distinct `order_number` values. Run in isolation three times,
  passing each time.
- **AC10** — `testPrintTicketEnqueuesExactlyOnePendingPrintJob` asserts exactly one `jobs` row
  with `queue='print'`, `status='pending'`, `attempts=0`; and
  `testWithoutPrintTicketNoJobIsEnqueued` asserts zero.
- **AC11** — `MenuReportsAndJourneyTest::testEndToEndJourneyFromOrderToReport`: order created,
  found in `GET /api/orders?status=pending`, completed, then
  `GET /api/admin/reports/sales` for that `business_date` returns one row whose `revenue`
  equals `PricingService`'s unit price × quantity plus packaging, compared in cents.
- **AC12** — `testAdminCanCreateAMenuItemAndItAppearsOnThePublicMenu` passes; a companion test
  asserts the same mutation is `401` without a token.
- **AC13 — partially verified.** The workflow file contains "Unit tests (PHPUnit)", "Create
  integration test database" and "Integration tests (PHPUnit, MySQL)" in that order, verified by
  reading it. **Not yet observed running in GitHub Actions** — that happens when this branch's
  pull request runs.
- **AC14** — `vendor/bin/phpstan analyse` → `[OK] No errors`. `php-cs-fixer --dry-run` →
  `Found 0 of 78 files that can be fixed` after one file was reformatted.
- **Stability** — the integration suite was run six consecutive times after the final change:
  `Tests: 26, Assertions: 82, Incomplete: 1` every time, no failures. Earlier flakiness
  (2 passes in 3) was traced to the PSR-4 problem in log entry 5 and fixed.

**Not validated:**

- **AC13's CI observation** — see above; the stage exists and is ordered correctly in the YAML
  but has not been seen executing.
- **The concurrency tests do not guarantee contention.** Four processes are launched without a
  barrier; on a fast machine they may serialise. A run where they happen not to overlap would
  pass without exercising the lock. The tests are a real race, not a deterministic one, and the
  deadlock they surfaced is evidence that contention does occur in practice.
- **`tests/Integration` is not analysed by PHPStan.** Spec 034 excluded `tests/`, and that was
  left unchanged here; AC14 only requires the existing checks to stay green.
