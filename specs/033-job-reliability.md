# Spec 033 — Job reliability: stale-reservation recovery and queue observability

## Metadata

- Status: Verified
- Created: 2026-09-19
- Updated: 2026-09-19
- Owner: Henry
- Related issue: Not applicable (roadmap item — `docs/ROADMAP.md`, `v1.8.0 — Reliability & Quality`, "Job reliability")
- Related branch: `033`

## Context

First item of the `v1.8.0 — Reliability & Quality` milestone, started after `v1.7.1` was
tagged. The roadmap names this one explicitly as high priority (`docs/ROADMAP.md`,
"Job reliability"), and the `v2.1.0 — Fiscal / NFC-e` milestone states that NFC-e issuance
must run on *this* hardened queue, because a duplicate fiscal document is a legal incident
rather than a cosmetic bug.

This is not a from-scratch item. Spec 008 (`specs/008-reliable-print-job-flow.md`) already
shipped the retry half: `attempts`, exponential backoff on release, `max_attempts`, failed
jobs kept in the table instead of deleted, and the `print-worker` service in
`docker-compose.yml`. Spec 008 recorded "Data model and migrations: Not applicable" — the
schema and observability half is what is still missing, plus the stale-reservation path.

Two findings from the investigation changed the scope the roadmap implies, and are recorded
here rather than silently resolved (per `CLAUDE.md`, "when code and spec conflict, report
the conflict"):

1. **"Atomic claiming" is already done.** The roadmap lists it as pending, but
   `JobService::processNext()` already claims inside `DB::transaction` with
   `lockForUpdate()` (`src/Services/JobService.php:42-61`). No work is required for it.
   This spec does not change the locking strategy.
2. **`completed_at` was impossible under current behavior.** A successful job is deleted
   (`src/Services/JobService.php:79`), so a `completed_at` column could never hold a value,
   and the roadmap's own rule — "every documented status is reachable through an actual code
   path", the same rule spec 020 used when it deleted the unused `preparing`/`ready` order
   statuses — would have been violated. **Decision taken before drafting: stop deleting
   successful jobs and keep the history**, which makes `completed_at` and a `completed`
   status real, and adds a retention routine so the table cannot grow without bound.

## Problem

1. **A job reserved by a worker that dies is stuck forever.**
   `processNext()` sets `reserved_at = now()` before executing the handler
   (`src/Services/JobService.php:55-58`), and the claiming query only selects
   `whereNull('reserved_at')` (`:44`). If the worker process dies between reservation and
   completion — crash, OOM, `docker kill`, host reboot — the row keeps `reserved_at` set.
   It is claimed, never finished, never retried, and nothing ever reports it.
2. **A failed or stuck job is invisible to the operator.** There is no `/api/admin/jobs`
   route (`src/Routes.php` has none), no admin screen mentions jobs, and
   `JobService::logJobFailure()` (`:100-110`) writes through PHP's `error_log()`, which goes
   to the PHP/stderr log — **not** to Monolog's `app.log`. So job-level failures do not even
   appear in the admin log viewer (`GET /api/admin/logs`, `src/Controllers/LogController.php`)
   that an operator would think to check. The kitchen's reprint button shows a green
   "Pedido #N enviado para impressão!" toast (`public/kitchen/app.js:368-379`) that is
   truthful only about *queueing*; if the printer is dead, nothing ever corrects it.
3. **A job with an unknown handler is deleted as if it succeeded.**
   `src/Services/JobService.php:73-79` executes the handler only `if ($handler &&
   class_exists($handler))`, then falls through to `$job->delete()` regardless. A renamed or
   removed handler class silently destroys the job with no error, no log line and no trace.
4. **The permanent-failure state is implicit.** An exhausted job is identified only by the
   tuple `attempts >= max_attempts` with `reserved_at = NULL`, excluded from the claim query
   by the `attempts < max_attempts` filter (`:46`). There is no marker column, no failure
   timestamp, and no record of *what* the error was.

## Goals

- A reservation that outlives a defined timeout is recovered: the job becomes claimable
  again, or is marked permanently failed if it has no attempts left.
- The `jobs` table records the state an operator needs: current status, last error,
  when it failed, when it completed.
- A failed or stuck job is discoverable without reading raw log files, and job failures
  reach `app.log` so the existing admin log viewer shows them.
- A job whose handler class cannot be resolved fails loudly instead of disappearing.
- Job history does not grow without bound.

## Non-goals

- **No Redis, RabbitMQ or queue library.** The roadmap is explicit: "The database-backed
  queue may remain part of GastroFlow Community. Improve it before replacing it."
- **No change to the locking strategy.** Claiming is already atomic (see Context); adding
  `SKIP LOCKED` would only matter with multiple concurrent workers on one queue, and
  Community runs a single `print-worker`. Out of scope, not an oversight.
- **No idempotency work.** The roadmap's "idempotency where critical" belongs to the fiscal
  issuance job in `v2.1.0`, which carries its own duplicate-prevention acceptance criteria.
  Reprinting a ticket twice is cosmetic; issuing an NFC-e twice is not.
- **No printing-reliability work.** `PrintService::printOrder()` returning normally when
  `printer_ip` is empty (`src/Services/PrintService.php:73-76`) — so an unconfigured printer
  makes the job "succeed" — is a real defect, but it belongs to this milestone's separate
  "Printing reliability" item, not here.
- **No structured logging / request IDs**, no realtime/SSE work, no audit history, no admin
  UI for the queue. Each is its own `v1.8.0` item and its own later spec.
- **No per-job `max_attempts`.** It is hardcoded to `3` at dispatch
  (`src/Services/JobService.php:30`); changing that is not required by any current need.

## Current behavior

All confirmed by reading the code, not assumed.

**Schema** — the `jobs` table is not in `common/sql/001_schema.sql`; it is created solely by
`common/migrations/007_jobs.sql:4-16`, and no later migration alters it:

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(50) NOT NULL DEFAULT 'default',
    payload LONGTEXT NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    reserved_at TIMESTAMP NULL DEFAULT NULL,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_queue_reserved (queue, reserved_at),
    INDEX idx_available (available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

No `status`, `last_error`, `failed_at`, `completed_at` or `reserved_until` column exists
anywhere in the repository (grep-confirmed; the only `status` column is on `orders`).

**Claim** — `src/Services/JobService.php:42-61`:

```php
$job = DB::transaction(function () use ($queue) {
    $job = Job::where('queue', $queue)
        ->whereNull('reserved_at')
        ->where('available_at', '<=', Carbon::now())
        ->where('attempts', '<', DB::raw('max_attempts'))
        ->orderBy('id')
        ->lockForUpdate()
        ->first();

    if (!$job) {
        return null;
    }

    $job->update([
        'reserved_at' => Carbon::now(),
        'attempts'    => $job->attempts + 1,
    ]);

    return $job;
});
```

`attempts` is incremented at claim time, before the handler runs — so an attempt is counted
even if the worker dies. FIFO by `id`, not by `available_at`.

**Execute / success** — `:68-79`: decodes the payload, and `if ($handler &&
class_exists($handler))` calls `$instance->handle($data, $job)`; then `$job->delete()`
unconditionally.

**Failure** — `:80-95`: `attempts >= max_attempts` → `reserved_at = null` and a permanent
failure log line; otherwise `reserved_at = null` plus `available_at = now + pow(2, attempts)`
seconds (2s, 4s, 8s) and a retry log line. Both paths call `logJobFailure()` (`:100-110`),
which uses `error_log()`.

**Model** — `src/Models/Job.php`: `$fillable` is `queue, payload, attempts, max_attempts,
reserved_at, available_at`; `$casts` covers `attempts`/`max_attempts` as integer and
`reserved_at`/`available_at` as datetime; `const UPDATED_AT = null` (no `updated_at` column).

**Worker** — `bin/worker`: `--once` calls `processNext()` once and always `exit(0)`;
continuous mode calls `processAll($queue, 10)` then sleeps 2s in 20×100ms slices. Signal
handling is guarded by `function_exists('pcntl_signal')` because the container lacks `pcntl`
(recorded in spec 008's implementation log). The worker only echoes counts; it never learns
that a job failed.

**Handler contract** — there is no interface or base class. `src/Jobs/PrintOrderJob.php` is
the only job. The contract is duck-typed by `JobService:74-75`: a zero-argument constructor
plus `handle(array $data, ?Job $job = null): void`, where failure is signalled by throwing.

**Dispatch callers** — `dispatch()` is called in exactly two places, both in
`src/Services/OrderService.php:35-37` (order creation, gated by `print_ticket`) and
`:101-103` (reprint). Nothing under `public/` calls it directly.

**Tests** — `tests/Unit/JobServiceTest.php` (5 tests) builds a SQLite `:memory:` copy of the
`jobs` schema in `setUp()` (`:38-47`) — a second, duplicate definition that must be kept in
sync with the migration. `lockForUpdate()` is a no-op on SQLite, so the claim's concurrency
semantics are currently untested.

**Config** — `src/Settings.php` reads environment variables through `get()`/`getRequired()`,
with typed accessors such as `getAppEnv()`, `isDebug()`, `getTimezone()` (spec 011), each
documenting its default and why that default is the safe one.

## Proposed behavior

### Reservation with an explicit deadline

A claim writes `reserved_until = now + reservation timeout` alongside `reserved_at`. The
claiming query never has to embed a timeout expression, and an operator reading the row can
see when the reservation expires. Chosen over the roadmap's alternative
(`reserved_at < now() - N minutes` computed in the query) because the deadline becomes data
rather than a value hardcoded into a query, which is what the fiscal milestone will need when
a SEFAZ call legitimately takes longer than a print job.

Timeout comes from `QUEUE_RESERVATION_TIMEOUT` (seconds, default **300**), read via a new
`Settings::getQueueReservationTimeout(): int`, following the `APP_ENV`/`APP_TIMEZONE` pattern
from spec 011. 300s is far above a print job's real duration (seconds) and far below a shift,
so a genuinely dead worker is recovered within five minutes.

### Stale-reservation recovery

Before claiming, `processNext()` sweeps the queue for reservations whose `reserved_until` has
passed:

- `attempts < max_attempts` → back to `pending`, `reserved_at`/`reserved_until` cleared,
  `available_at = now`. The attempt already consumed at claim time is **not** refunded, so a
  job that reliably kills its worker exhausts `max_attempts` and stops — it cannot loop
  forever.
- `attempts >= max_attempts` → `failed`, with `failed_at = now` and a `last_error` saying the
  reservation expired. Without this branch such a job would sit in `reserved` forever, since
  the claim query excludes it.

The sweep is two indexed `UPDATE`s that normally match zero rows, run on every
`processNext()`. Deliberately not optimized with a memoized "last swept at" timestamp — the
roadmap's own "Query performance" item warns against speculative optimization, and correctness
under `--once` (which calls `processNext()` directly) is worth more than avoiding two no-op
statements per tick.

### Explicit status

`status` becomes the primary state, with four values, each reachable by a real code path:

| status | written by |
|---|---|
| `pending` | `dispatch()`; release after a retryable failure; stale recovery with attempts left |
| `reserved` | claim |
| `completed` | handler success |
| `failed` | `attempts >= max_attempts` after a throw; stale recovery with no attempts left; unresolvable handler |

The claim query selects `status = 'pending'` instead of `reserved_at IS NULL`. The
`attempts < max_attempts` and `available_at <= now` filters stay.

### Success keeps the row

On success the job is **no longer deleted**: `status = 'completed'`, `completed_at = now`,
`reserved_at`/`reserved_until` cleared, `last_error` cleared. This is what makes
`completed_at` meaningful and lets an operator answer "did order #123's ticket print?" from
the database instead of from log archaeology.

To keep the table bounded, `JobService::pruneCompleted(int $days): int` deletes `completed`
jobs older than `QUEUE_RETENTION_DAYS` (default **7**, via
`Settings::getQueueRetentionDays(): int`). The continuous worker calls it at most once per
hour (an in-process timestamp — no `pcntl`, no cron dependency); `bin/jobs-prune` exposes it
manually. `failed` jobs are never pruned automatically — they are the diagnostic record.

### Unresolvable handler fails loudly

`if ($handler && class_exists($handler))` stops being a silent skip. A missing or
unresolvable handler class throws a `RuntimeException`, which lands in the existing failure
path and therefore in `status`/`last_error` like any other failure.

### Failures reach `app.log`

`logJobFailure()` stops using `error_log()` and writes through Monolog to
`Settings::getLogFile()`, so failures appear in the existing admin log viewer. `JobService`
gains an optional constructor logger (`?LoggerInterface $logger = null`) falling back to a
lazily-built app logger, mirroring `src/Jobs/PrintOrderJob.php:32-38`. `LoggerInterface` is
already registered in the DI container (`src/App.php:35-39`), so autowired construction keeps
working, and `new JobService()` in `bin/worker` keeps working via the default.

### Operator signal

`bin/jobs-status [queue]` prints the jobs that need attention — `failed` ones, and `reserved`
ones past their deadline — as `id`, queue, status, `attempts/max_attempts`, age,
`failed_at`, and a truncated `last_error`, followed by a one-line summary count. Read-only,
exit code `0`. Chosen over a new `GET /api/admin/jobs` endpoint because the roadmap's item
asks for failed-job *visibility*, not an API surface, and `v2.1.0`'s "Admin Order History"
is where a queue/fiscal monitoring UI is actually specified.

## Functional requirements

1. FR1 — Migration `016` adds `status`, `reserved_until`, `last_error`, `failed_at` and
   `completed_at` to `jobs`, is idempotent, and is a no-op on a second `bin/migrate` run.
2. FR2 — Migration `016` backfills existing rows: `attempts >= max_attempts` → `failed`;
   `reserved_at IS NOT NULL` with attempts left → `reserved` with a `reserved_until` already
   in the past (so the first sweep recovers today's stuck jobs); everything else → `pending`.
3. FR3 — A claim sets `status = 'reserved'`, `reserved_at = now`,
   `reserved_until = now + QUEUE_RESERVATION_TIMEOUT`, and increments `attempts`.
4. FR4 — The claim query selects only `status = 'pending'` AND `available_at <= now` AND
   `attempts < max_attempts`, ordered by `id`, inside the existing
   `DB::transaction` + `lockForUpdate()`.
5. FR5 — Before claiming, jobs with `status = 'reserved'` and `reserved_until < now` and
   `attempts < max_attempts` return to `pending` with `reserved_at`/`reserved_until` cleared
   and `available_at = now`.
6. FR6 — Before claiming, jobs with `status = 'reserved'` and `reserved_until < now` and
   `attempts >= max_attempts` become `failed` with `failed_at = now` and a `last_error`
   identifying an expired reservation.
7. FR7 — Stale recovery does not decrement or reset `attempts`.
8. FR8 — On handler success the row is kept with `status = 'completed'`,
   `completed_at = now`, `reserved_at = NULL`, `reserved_until = NULL`, `last_error = NULL`.
9. FR9 — On a retryable failure (`attempts < max_attempts`) the job returns to `pending` with
   the existing `pow(2, attempts)` backoff applied to `available_at`, and `last_error` set to
   the exception message.
10. FR10 — On a permanent failure (`attempts >= max_attempts`) the job becomes `failed` with
    `failed_at = now` and `last_error` set to the exception message.
11. FR11 — `last_error` stores at most 1000 characters, truncated, and never contains the job
    payload.
12. FR12 — A payload whose handler is missing, empty, or not a loadable class throws, and the
    job therefore ends in `pending` (retryable) or `failed`, never deleted.
13. FR13 — `JobService::pruneCompleted(int $days)` deletes only `status = 'completed'` rows
    with `completed_at` older than `$days`, and returns how many it deleted.
14. FR14 — The continuous worker calls `pruneCompleted()` at most once per hour; `--once` never
    prunes.
15. FR15 — `bin/jobs-prune [--days=N]` runs the same prune on demand and prints the count.
16. FR16 — `bin/jobs-status [queue]` lists `failed` jobs and expired `reserved` jobs with id,
    queue, status, attempts/max_attempts, age, `failed_at` and truncated `last_error`, plus a
    summary count; it modifies nothing and exits `0`.
17. FR17 — Job failure log lines go to `Settings::getLogFile()` via Monolog and include job id,
    queue, attempt/max_attempts and the error message.
18. FR18 — `src/Models/Job.php` exposes the new columns in `$fillable` with `$casts` mapping
    `reserved_until`/`failed_at`/`completed_at` to datetime.

## Non-functional requirements

- **Compatibility**: the migration must run against a database with live jobs in it, without
  manual intervention, and without changing how a healthy job flows through the queue.
- **No new dependencies.** Monolog, Carbon and Eloquent are already present.
- **Observability without secrets**: log lines and `bin/jobs-status` output must not print the
  payload (it may carry order data) — job id, queue, attempt counters, timestamps and the
  exception message only.
- **Performance**: the claim path must stay a single indexed lookup; the sweep must be indexed
  on `(queue, status)`.
- **No `pcntl` assumption** anywhere in the new worker code.

## User flows

- **Kitchen/cashier (unchanged)** — creating an order or pressing reprint enqueues a job and
  returns immediately. Nothing about the request/response changes.
- **Worker, healthy job** — claim (`reserved`) → handler runs → `completed` with
  `completed_at`; the row is pruned 7 days later.
- **Worker, failing printer** — claim → throw → `pending` with backoff and `last_error`, twice
  more → `failed` with `failed_at` and `last_error`; the failure now also appears in the admin
  log viewer.
- **Worker killed mid-job** — the row stays `reserved` with a `reserved_until` in the future;
  once that passes, the next `processNext()` returns it to `pending` (or marks it `failed` if
  attempts ran out) and it is retried on the following tick.
- **Operator/administrator** — runs `docker compose exec web php bin/jobs-status print`, sees
  the failed and stuck jobs with their errors, and can read the same failures in
  Admin → Logs.

## API changes

Not applicable. No endpoint is added, removed or changed. The operator signal is a CLI
command by deliberate choice (see Proposed behavior); `GET /api/admin/jobs` is explicitly a
non-goal.

## Data model and migrations

New file `common/migrations/016_job_reliability.sql`, following the house style of
`015_build_your_own_dish.sql`: `SET NAMES utf8mb4;` / `SET FOREIGN_KEY_CHECKS = 0;` header, a
banner comment citing this spec number and stating the idempotency guarantee, numbered
sections, and the canonical guarded `ADD COLUMN` pattern
(`information_schema.COLUMNS` count → `PREPARE`/`EXECUTE`/`DEALLOCATE`, with `'SELECT 1 AS
dummy'` as the no-op branch and a **distinct session variable name per block**).

| column | type | notes |
|---|---|---|
| `status` | `ENUM('pending','reserved','completed','failed') NOT NULL DEFAULT 'pending'` | after `payload` |
| `reserved_until` | `TIMESTAMP NULL DEFAULT NULL` | after `reserved_at` |
| `last_error` | `VARCHAR(1000) NULL DEFAULT NULL` | after `available_at` |
| `failed_at` | `TIMESTAMP NULL DEFAULT NULL` | after `last_error` |
| `completed_at` | `TIMESTAMP NULL DEFAULT NULL` | after `failed_at` |

Indexes: add `idx_queue_status_available (queue, status, available_at)` for the claim and the
sweep, and `idx_status_completed (status, completed_at)` for pruning. The existing
`idx_queue_reserved (queue, reserved_at)` becomes unused by the new claim query but is left in
place — dropping it is not required by any requirement here.

Backfill (idempotent `UPDATE`s, re-running changes nothing):

```sql
UPDATE jobs SET status = 'failed'   WHERE attempts >= max_attempts;
UPDATE jobs SET status = 'reserved', reserved_until = reserved_at
    WHERE reserved_at IS NOT NULL AND attempts < max_attempts;
-- everything else keeps the column default, 'pending'
```

`failed_at` stays `NULL` for pre-existing failures — the real failure time was never recorded
and inventing one would be worse than an honest gap. Setting `reserved_until = reserved_at`
on legacy stuck rows puts their deadline in the past, so the first sweep after deployment
recovers exactly the jobs this spec exists to fix.

`src/Models/Job.php` gains the five columns in `$fillable`, and `$casts` entries mapping
`reserved_until`, `failed_at` and `completed_at` to `datetime`.

## Architecture and affected components

Matches the project's real layering — `JobService` already exists and stays the single owner
of queue semantics. **No `JobRepository` is introduced**; `CLAUDE.md` forbids adding a layer
for a domain that doesn't have one purely for symmetry, and nothing here needs one.

- `src/Services/JobService.php` — sweep, claim, success, failure, prune, Monolog logging.
- `src/Models/Job.php` — `$fillable`, `$casts`.
- `src/Settings.php` — `getQueueReservationTimeout()`, `getQueueRetentionDays()`.
- `bin/worker` — hourly prune call; no `pcntl` assumption.
- `bin/jobs-status` (new), `bin/jobs-prune` (new) — thin CLI wrappers over `JobService`,
  bootstrapping like `bin/worker` does.
- `common/migrations/016_job_reliability.sql` (new).
- `tests/Unit/JobServiceTest.php` — extend; its duplicated SQLite schema must gain the new
  columns or every new write fails with "no such column".
- `.env.example` — document `QUEUE_RESERVATION_TIMEOUT` and `QUEUE_RETENTION_DAYS`.
- `docs/architecture.md` — update the queue description to the new state machine.
- `src/Jobs/PrintOrderJob.php` — **unchanged**; the `handle(array $data, ?Job $job = null)`
  contract is preserved.

## Security considerations

No authentication or authorization boundary is touched: no endpoint is added, and the two new
CLI commands require shell access to the container, which is already a higher privilege than
any application role. The one real concern is disclosure through the new observability
surface: `last_error` and the log lines carry exception messages, which must not include the
payload (FR11) — a print payload references an order, and the roadmap's structured-logging
item sets the same "never log secrets" rule. `bin/jobs-status` prints no payload.

## Backward compatibility

- **Behavior change, intentional and user-visible in the database**: successful jobs are no
  longer deleted. Anything that inferred "job finished" from the row's absence would now be
  wrong. Grep-confirmed that nothing does: `dispatch()` has two callers
  (`src/Services/OrderService.php:35-37`, `:101-103`), neither reads the job back, and no
  route or frontend file queries the `jobs` table.
- **Test break, expected**: `tests/Unit/JobServiceTest.php::testSuccessfulJobIsDeleted`
  asserts the opposite of FR8 and must be rewritten to assert `completed` +
  `completed_at`, not deleted alongside the new behavior.
- **Existing rows** are handled by the FR2 backfill; the currently-stuck jobs in a live
  database are recovered rather than requiring manual `UPDATE`s.
- **Queue semantics for a healthy job are unchanged**: same FIFO order, same
  `pow(2, attempts)` backoff, same `max_attempts = 3`, same handler contract.
- **Disk growth**: new, bounded by the 7-day retention. A restaurant creating a few hundred
  orders a day keeps a few thousand rows — negligible, and `failed` rows are kept
  deliberately.

## Acceptance criteria

- AC1 — Given a job with `status='reserved'`, `attempts=1`, `max_attempts=3` and
  `reserved_until` one second in the past, `reclaimStaleReservations('print')` returns it to
  `pending`, clears `reserved_at`/`reserved_until` and leaves `attempts` at `1`; the job is
  then claimed and completed by `processNext('print')`.
  *(Wording corrected during implementation — see Implementation log entry 1. The original
  text said `processNext()` itself would leave it `pending` for a later call; in reality
  `processNext()` sweeps and then claims in the same call, so recovery and re-execution
  happen in one pass. The sweep is therefore asserted through `reclaimStaleReservations()`
  directly.)*
- AC2 — Given the same job but with `attempts=3, max_attempts=3`, `processNext('print')`
  leaves it `failed` with `failed_at` non-null and `last_error` mentioning the expired
  reservation, and it is **not** re-claimed.
- AC3 — A successful job ends with `status='completed'`, `completed_at` non-null,
  `reserved_at` and `reserved_until` null, and the row still present.
- AC4 — A handler that throws with attempts remaining leaves `status='pending'`,
  `available_at` in the future, `last_error` equal to the exception message (truncated at 1000
  chars), and `failed_at` null.
- AC5 — A handler that throws on its last attempt leaves `status='failed'`, `failed_at`
  non-null, `last_error` set, and `processNext()` returns `false` on the next call.
- AC6 — A payload naming a non-existent handler class leaves the job in `pending` or `failed`
  with `last_error` naming the unresolvable handler; `Job::find($id)` is not null.
- AC7 — A claim writes `reserved_until` equal to `reserved_at + QUEUE_RESERVATION_TIMEOUT`
  (±1s).
- AC8 — `pruneCompleted(7)` deletes a `completed` job with `completed_at` 8 days old, keeps
  one 6 days old, and keeps a `failed` job of any age.
- AC9 — `bin/migrate` applies `016_job_reliability.sql` with `[OK]`; a second run prints
  `✔ Nenhuma migração pendente.`; `SHOW COLUMNS FROM jobs` lists the five new columns.
- AC10 — After the migration on a database seeded with one exhausted job, one stuck reserved
  job and one fresh job, their statuses are `failed`, `reserved` (with `reserved_until` in the
  past) and `pending` respectively.
- AC11 — `bin/jobs-status print` on that database lists the failed and the stuck job, does not
  list the pending one, prints no payload, and exits `0`.
- AC12 — A job failure writes a line to `logs/app.log` containing the job id, queue and
  attempt counter — verified by reading the file, not by inspecting the code.
- AC13 — `docker compose exec web vendor/bin/phpunit` passes, with the rewritten
  `testSuccessfulJobIsDeleted` and new cases for AC1–AC8.

## Implementation plan

1. `common/migrations/016_job_reliability.sql` — five guarded `ADD COLUMN` blocks, two
   indexes, the three backfill `UPDATE`s. Run `bin/migrate` twice to prove idempotency.
2. `src/Models/Job.php` — `$fillable` + `$casts`.
3. `src/Settings.php` — the two typed accessors, each with a docblock stating its default and
   why, matching the spec 011 style.
4. `tests/Unit/JobServiceTest.php` — add the new columns to the SQLite `setUp()` schema first,
   so subsequent steps are testable.
5. `src/Services/JobService.php` — `dispatch()` writes `status='pending'`; claim writes
   `status`/`reserved_until`; success path keeps the row; both failure paths write
   `status`/`last_error`/`failed_at`.
6. `src/Services/JobService.php` — `reclaimStaleReservations()`, called at the top of
   `processNext()`.
7. `src/Services/JobService.php` — unresolvable handler throws.
8. `src/Services/JobService.php` — optional `?LoggerInterface` constructor arg and Monolog
   `logJobFailure()`.
9. `src/Services/JobService.php` — `pruneCompleted()`; `bin/worker` hourly call.
10. `bin/jobs-status` and `bin/jobs-prune`.
11. Tests for AC1–AC8; rewrite `testSuccessfulJobIsDeleted`.
12. `.env.example` and `docs/architecture.md`.

## Testing and validation strategy

**Correction to this skill's own guidance:** `/spec-plan`'s instructions say to state that the
project has no automated test infrastructure, citing `specs/000-project-baseline.md`. That is
stale — the baseline was corrected on 2026-09-03 (`specs/000-project-baseline.md:143`) and
PHPUnit plus GitHub Actions CI have existed since specs 004/005. This spec uses the real test
suite.

- **Unit (PHPUnit, SQLite `:memory:`)** — AC1–AC8, extending `tests/Unit/JobServiceTest.php`.
  Stale reservations are simulated by writing `reserved_until` into the past directly, never by
  killing a process. Run with `docker compose exec web vendor/bin/phpunit`.
- **Migration (real MySQL)** — AC9/AC10 against the `db` container: seed the three job rows,
  run `bin/migrate` twice, `SHOW COLUMNS FROM jobs`, and read back the three statuses.
- **CLI (real MySQL)** — AC11 by running `bin/jobs-status` against that seeded database and
  pasting its actual output into the validation evidence.
- **Logging** — AC12 by forcing a job failure and `tail`-ing `logs/app.log`.
- **Known coverage gap, stated rather than papered over**: `lockForUpdate()` is a no-op under
  SQLite, so concurrent claiming remains untested by the unit suite. This spec does not change
  the locking strategy, so it introduces no new risk there, but a genuine two-worker
  concurrency test belongs to this milestone's separate "Integration tests" item (MySQL-backed)
  and is **not** claimed as covered here.
- Docker must be running before any of the above; it was down earlier in the session this spec
  was drafted in.

## Rollout and rollback

Rollout: `bin/migrate`, then restart the `print-worker` service so it picks up the new code
(`docker compose up -d`). No downtime for the cashier or kitchen — the queue keeps accepting
dispatches throughout, and the migration is additive.

Rollback: revert the code commit and restart the worker. The added columns can stay in place
harmlessly — the previous code neither reads nor writes them, and `reserved_at`/`available_at`
/`attempts` keep the meaning they had. The one asymmetry to be aware of: jobs that completed
under the new code are rows the old code would have deleted, so after a rollback they sit
`completed` and are simply ignored by the old claim query (`reserved_at IS NULL` plus
`attempts < max_attempts` would re-select them — **they must be deleted manually on rollback**,
or the old worker would re-run them). Recorded here rather than discovered later.

## Open questions

None blocking. Two decisions were taken with the owner before drafting and are recorded in
Context: keeping successful jobs (with retention) rather than dropping `completed_at`, and a
CLI command rather than an admin endpoint for the operator signal.

Non-blocking, deliberately deferred: whether `max_attempts` should become a `dispatch()`
parameter (the fiscal milestone may want a different value) — no current need, so not done
here.

## Task checklist

- [x] 1. Migration `016_job_reliability.sql` (columns, indexes, backfill)
- [x] 2. `Job` model `$fillable`/`$casts` (+ `STATUS_*` constants)
- [x] 3. `Settings` accessors for timeout and retention
- [x] 4. SQLite test schema updated
- [x] 5. `JobService` status writes (dispatch/claim/success/failure)
- [x] 6. `reclaimStaleReservations()` wired into `processNext()`
- [x] 7. Unresolvable handler throws
- [x] 8. Monolog failure logging
- [x] 9. `pruneCompleted()` + hourly worker call
- [x] 10. `bin/jobs-status`, `bin/jobs-prune` (+ `JobService::getJobsNeedingAttention()`)
- [x] 11. Tests for AC1–AC8 + replace `testSuccessfulJobIsDeleted`
- [x] 12. `.env.example` + `docs/architecture.md` — see Implementation log entry 5 for why this
  item was initially blocked and how it was unblocked.

## Implementation log

- **2026-09-19 — 1. AC1 wording vs. real behavior.** `processNext()` sweeps stale reservations
  *and then* claims in the same call, so a recovered job is re-executed immediately rather than
  on a later call, as AC1 originally described. The implementation was kept (faster recovery,
  and `--once` works in a single invocation) and AC1's wording was corrected instead, with the
  change flagged in place rather than made silently. The sweep is asserted directly through
  `reclaimStaleReservations()`.
- **2026-09-19 — 2. `pruneCompleted()` signature.** FR13 specified `pruneCompleted(int $days)`.
  Implemented as `pruneCompleted(?int $days = null)`, falling back to
  `Settings::getQueueRetentionDays()` when omitted, so `bin/worker` and `bin/jobs-prune` do not
  each have to resolve the setting. Still satisfies FR13 for an explicit integer argument.
- **2026-09-19 — 3. `whereColumn` vs. `DB::raw` for the attempts comparison.** New code in
  `reclaimStaleReservations()` uses `whereColumn('attempts', '>=', 'max_attempts')`. The
  pre-existing `->where('attempts', '<', DB::raw('max_attempts'))` in the claim query was left
  untouched: it works, and rewriting it would have been an unrequested change to the one query
  whose behavior must not regress. The file is therefore mixed-style on this point, deliberately.
- **2026-09-19 — 4. `getJobsNeedingAttention()` added.** Not named in the implementation plan,
  but `bin/jobs-status` needed the "failed OR expired reservation" query and `JobService` is the
  right owner — putting it in the CLI script would have duplicated queue semantics outside the
  service.
- **2026-09-19 — 5. `.env.example` was blocked by a permission-config bug, then unblocked.**
  Three attempts to touch `.env.example` were denied. Root cause found afterwards in
  `.claude/settings.json`: the file was explicitly allowed (`Read/Write/Edit(./.env.example)`
  in `allow`, plus `sandbox.filesystem.allowRead`), but the `deny` entry `Read(./.env.*)`
  matched it too, and `deny` takes precedence over `allow`. With the owner's approval the
  wildcard was replaced by explicit denies for the variants that actually carry secrets
  (`.env.local`, `.env.development`, `.env.test`, `.env.production`), leaving `Read(./.env)`
  and `Read(./secrets/**)` intact. `.env.example` then documented both new variables.
  **Note for future work:** a new `.env.<something>` variant is no longer denied by default —
  it must be added to that list explicitly.
- **2026-09-19 — 6. Two stale claims corrected in `docs/architecture.md`.** The "Background jobs
  and printing" paragraph being rewritten still said the worker "must be supervised separately"
  and that print failures are "intentionally never thrown". Both stopped being true with spec
  008 (which added the `print-worker` compose service and made `PrintService` rethrow). They
  were corrected as part of rewriting that paragraph rather than left as known-false text.
- **2026-09-19 — 7. Test bug, not an implementation bug.** The first run of the AC7 test failed
  (`Failed asserting that null is not null`). Cause: the probe handler stored the `Job` *model
  reference*, and the success path nulls `reserved_at`/`reserved_until` on that same object
  immediately after `handle()` returns. Fixed by copying the two Carbon values inside the
  handler. The implementation was correct; verified independently with a standalone script
  before changing anything.
- **2026-09-19 — 8. Real-world finding.** After the migration, the live `print` queue backfilled
  **47 rows to `failed`** (all `attempts=3/3`), accumulated over about two weeks of an
  unreachable printer. They were previously invisible — exactly the condition this spec exists
  to surface. Their `last_error` is empty because the column did not exist when they failed.

## Validation evidence

All commands run inside the running containers on 2026-09-19. Live-database checks used a
dedicated `spec033` queue so the running `print-worker` (still executing pre-change code)
could not interfere; all `spec033` rows were deleted afterwards.

- **AC13** — `docker compose exec -T web vendor/bin/phpunit` → `OK (133 tests, 231 assertions)`
  (was 127 tests before this spec; 6 new cases). First run was `133 tests, 1 failure` — see
  Implementation log entry 7.
- **AC1** — `JobServiceTest::testStaleReservationWithAttemptsLeftGoesBackToPending` passes:
  `reclaimStaleReservations('print')` returns `1`, status → `pending`,
  `reserved_at`/`reserved_until` null, `attempts` stays `1`, then `processNext()` → `completed`.
- **AC2** — `testStaleReservationWithNoAttemptsLeftBecomesFailed` passes: `processNext()`
  returns `false`, status → `failed`, `failed_at` non-null, `last_error` contains
  `Reserva expirada`.
- **AC3** — `testSuccessfulJobIsKeptAsCompleted` passes: row present, `completed`,
  `completed_at` non-null, `reserved_at`/`reserved_until`/`last_error` null.
- **AC4** — `testFailingJobIsReleasedForRetryBeforeMaxAttempts` passes: `pending`,
  `available_at` in the future, `last_error = 'simulated handler failure'`, `failed_at` null.
- **AC5** — `testMaxAttemptsReachedMarksJobFailedAndNotReprocessed` passes: `failed`,
  `attempts=3`, `failed_at` non-null, `last_error` set, next `processNext()` → `false`.
- **AC6** — `testUnresolvableHandlerFailsInsteadOfBeingDeleted` passes (row kept, `failed`,
  `last_error` contains `ThisClassDoesNotExist`). Confirmed live as well — see AC12's log line,
  where job #59's handler `"X"` produced
  `Handler do job não pôde ser resolvido: "X".` instead of a silent delete.
- **AC7** — `testClaimSetsReservationDeadlineFromSettings` passes:
  `reserved_until - reserved_at` within 1s of `getQueueReservationTimeout()` (300).
- **AC8** — `testPruneRemovesOldCompletedJobsOnly` passes (8-day completed pruned, 6-day kept,
  30-day failed kept). Also live: two completed rows seeded at 8 and 6 days old →
  `php bin/jobs-prune --days=7` → `Jobs concluídos removidos: 1`; re-query confirmed `#61`
  (8 days) gone and `#62` (6 days) still present.
- **AC9** — `php bin/migrate` → `▶ Executando 016_job_reliability.sql ... [OK]`; second run →
  `✔ Nenhuma migração pendente.` `SHOW COLUMNS FROM jobs` lists
  `status enum('pending','reserved','completed','failed') NOT NULL DEFAULT 'pending'`,
  `reserved_until timestamp NULL`, `last_error varchar(1000) NULL`, `failed_at timestamp NULL`,
  `completed_at timestamp NULL`. `SHOW INDEX FROM jobs` lists `idx_queue_status_available
  (queue, status, available_at)` and `idx_status_completed (status, completed_at)`.
- **AC10** — three rows seeded on `spec033` *before* migrating (`attempts=3/3` with no
  reservation; `attempts=1/3` reserved 2h earlier; fresh `attempts=0/3`). After `bin/migrate`:
  `#58 status=failed`, `#59 status=reserved reserved_until='2026-09-19 11:10:26'` (in the past),
  `#60 status=pending`. `failed_at` is `NULL` on `#58` as specified for legacy failures.
- **AC11** — `php bin/jobs-status spec033` listed exactly `#58 failed 3/3` and `#59 travado 1/3`,
  omitted the pending `#60`, printed no payload, summary
  `Total: 2 — 1 com falha permanente, 1 com reserva expirada.`, exit `0`.
- **AC12** — `php bin/worker spec033 --once` then `tail -3 logs/app.log` →
  `[2026-09-19T13:11:03.346837-03:00] job.ERROR: Job liberado para retry em 4s (attempt=2/3)
  {"job_id":59,"queue":"spec033","attempt":2,"max_attempts":3,"error":"Handler do job não pôde
  ser resolvido: \"X\"."}` — job id, queue, attempt/max_attempts and the message present, no
  payload. This same run also demonstrates the end-to-end stale path live: `#59` was
  stale-`reserved`, was swept back to `pending`, re-claimed (`attempts` 1 → 2) and failed
  retryably.
- **Syntax** — `php -l` clean on all seven changed/created PHP files (`src/Services/JobService.php`,
  `src/Models/Job.php`, `src/Settings.php`, `bin/worker`, `bin/jobs-status`, `bin/jobs-prune`,
  `tests/Unit/JobServiceTest.php`).

**Not validated, stated rather than implied:**

- **Concurrency.** `lockForUpdate()` is a no-op on SQLite, so two workers claiming the same job
  remains untested, exactly as this spec's Testing strategy predicted. The locking strategy was
  not changed, so no new risk was introduced — but no evidence is claimed either. Belongs to
  this milestone's MySQL-backed "Integration tests" item.
- **The hourly prune inside `bin/worker`** (FR14) was not observed firing: it needs a
  worker running for over an hour. The same `pruneCompleted()` call path was validated through
  `bin/jobs-prune` (AC8) and the unit test; only the once-per-hour trigger in the loop is
  unexercised.
- ~~`.env.example`~~ — done; `QUEUE_RESERVATION_TIMEOUT` and `QUEUE_RETENTION_DAYS` are
  documented there under a "Job queue (bin/worker)" section, with their defaults stated.
- **The running `print-worker` container still executes pre-change code.** It must be restarted
  (`docker compose up -d` or `docker compose restart print-worker`) for the new queue behavior to
  take effect in the live environment. Not done here — restarting a service was outside what this
  session was asked to do.
