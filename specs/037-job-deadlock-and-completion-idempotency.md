# Spec 037 — Job queue: deadlock handling and completion idempotency

## Metadata

- Status: Verified
- Created: 2026-09-24
- Updated: 2026-09-24
- Owner: Henry
- Related issue: Not applicable (defect discovered by spec 035's integration tests)
- Related branch: `037`

## Context

Spec 035 added MySQL-backed integration tests specifically to cover what the SQLite unit suite
cannot. The first thing they found was a real defect in the job queue — one that neither spec
008 (retry/backoff) nor spec 033 (stale-reservation recovery) could have caught, because both
were validated against SQLite where `FOR UPDATE` is a no-op and deadlocks do not exist.

This spec fixes it. It is scheduled ahead of the remaining `v1.8.0` roadmap items because the
`v2.1.0` fiscal milestone states plainly that NFC-e issuance runs on this queue and that a
duplicate document is "a legal/financial incident, not a cosmetic bug". The roadmap deferred
"idempotency where critical" to `v2.1.0` on the assumption that the queue underneath was sound.
The finding below says it is not yet.

## Problem

**Observed** by `tests/Integration/ConcurrencyTest::testTwoWorkersCannotClaimTheSameJob` with
four workers contending for one job against real MySQL 8.0.

`JobService::processNext()` (`src/Services/JobService.php:93-121`) wraps **three unrelated
things in one `try`**:

1. resolving the handler from the payload;
2. `$instance->handle($data, $job)` — the actual work;
3. the `$job->update([... STATUS_COMPLETED ...])` that records the result.

MySQL raises `SQLSTATE[40001] / 1213 Deadlock found` on **(3)** — after the handler has already
done its work. The single `catch (\Throwable $e)` treats that exactly like a handler failure:
`recordFailure()` returns the job to `pending` with the deadlock text in `last_error`, and
`processNext()` returns `true`, so the worker exits `0` reporting success.

**The job's work was performed, and the job is now queued to be performed again.** For
`PrintOrderJob` that is a duplicate ticket. For `v2.1.0`'s `IssueFiscalDocumentJob` it would be
a duplicate NFC-e.

Two manifestations, both observed:

- **Silent, and worse.** No stderr at all; the job sits `pending` with `1213` in `last_error`.
  This is how the integration test detects it today.
- **Loud.** The worker process dies with the deadlock on stderr, the nested message showing
  `recordFailure()`'s own `UPDATE` deadlocking while handling the first deadlock.

**Contributing cause, and why contention exists at all.** `processNext()` calls
`reclaimStaleReservations()` on **every** invocation (`:60`), which issues two unbounded
`UPDATE`s over `(queue, status, reserved_until)` — precisely the rows and index ranges that
concurrent claim transactions are locking. Spec 033 chose that deliberately ("deliberately not
optimized… correctness under `--once` is worth more than avoiding two no-op statements per
tick") and recorded the trade-off; it now has a measured cost. Those two `UPDATE`s also sit
**outside any `try`**, so a deadlock there propagates out of `processNext()` and kills the
worker outright.

**Nothing retries.** There is no deadlock handling anywhere in `JobService` — not around the
claim transaction, not around the completion update, not around `recordFailure()`'s update, and
not around the sweep. A deadlock is a transient, expected condition under contention; treating
it as a permanent failure is the root error.

## Goals

- A job whose handler ran successfully is **never** re-queued because recording the result lost
  a lock race.
- Deadlocks are retried where retrying is safe, and never by re-running a handler.
- "The handler failed" and "recording the result failed" become distinguishable outcomes.
- A deadlock is visible in the log instead of being swallowed into `last_error`.
- `ConcurrencyTest` stops reporting `Incomplete`.

## Non-goals

- **No new dependency, no Redis, no queue library.** The roadmap is explicit: improve it before
  replacing it.
- **No handler-level idempotency.** Making `PrintOrderJob` or the future fiscal job safe to run
  twice is a different problem, and `v2.1.0` owns it. This spec's job is to stop the queue from
  *causing* the second run.
- **No change to the retry/backoff/`max_attempts` semantics** from spec 008, or to the
  stale-reservation recovery from spec 033 — both must keep working unchanged.
- **No other `v1.8.0` items.** Printing/realtime reliability, structured logging, audit history,
  health checks, migration reliability and backup/restore stay in their own later specs.

## Current behavior

Confirmed by reading `src/Services/JobService.php` on 2026-09-24.

- `processNext()` — sweep (`:60`, untried), then claim inside
  `DB::transaction` + `lockForUpdate()` (`:65-87`), then the single `try` described above
  (`:93-121`). `reclaimStaleReservations()` issues two `UPDATE`s; neither is retried.
- `recordFailure()` — one `UPDATE` on the permanent-failure branch, one on the retry branch,
  plus `logJobFailure()` through Monolog. No deadlock handling; its `UPDATE` was observed
  deadlocking while handling another deadlock.
- The claim uses `lockForUpdate()`, i.e. plain `FOR UPDATE`. Losers **block** until the winner
  commits, rather than skipping. MySQL 8.0 supports `SKIP LOCKED`, which the codebase does not
  use anywhere.
- `Job` has `status` (`pending`/`reserved`/`completed`/`failed`), `attempts`, `max_attempts`,
  `reserved_at`, `reserved_until`, `last_error`, `failed_at`, `completed_at` (migrations `007`
  and `016`).
- **Tests**: `tests/Unit/JobServiceTest.php` runs on SQLite and **cannot reproduce a deadlock** —
  it is the wrong engine, by construction. `tests/Integration/ConcurrencyTest.php` reproduces it
  against MySQL and currently calls `markTestIncomplete()` with this defect's description; that
  call must be removed when this spec lands.

## Proposed behavior

### 1. Split the `try`, because the two failures are not the same failure

`processNext()` stops wrapping handler execution and result recording in one block. Handler
resolution and `handle()` keep going to `recordFailure()`. Recording the result gets its own
path, which **must not** re-queue the job — the work is already done.

### 2. Retry statements, never the handler

A small deadlock-aware retry helper wraps individual database operations: the claim transaction,
the completion update, `recordFailure()`'s updates, and the sweep. It retries only on
`SQLSTATE 40001` / error `1213` (and, to be decided during implementation, `1205` lock-wait
timeout), a small bounded number of times with a short backoff.

**It never wraps `handle()`.** That is the whole point: retrying the unit that contains the
handler is what would duplicate the work.

### 3. The end state when the result cannot be recorded

If the completion update still fails after its retries, the job must not silently return to
`pending`. The chosen end state is **`failed`, with an explicit `last_error` saying the handler
already ran and the completion could not be recorded**, plus a Monolog entry at error level.

Rationale: `failed` is terminal and not re-claimed, so the work is not repeated. It is
deliberately an alarming state — an operator seeing it should investigate, because the true
situation is "work done, bookkeeping lost". The alternative, leaving it `reserved`, would be
silently re-run by the stale-reservation sweep, which is exactly the bug being fixed.

This is the decision that actually protects the fiscal job later: the queue's contract becomes
"a job is run at most once unless its handler explicitly asks to be retried", instead of
"a job may be re-run whenever bookkeeping hiccups".

### 4. Reduce the contention that creates the deadlock

Two changes, both to be validated by measurement rather than assumed:

- **`SKIP LOCKED` on the claim.** Losers skip the locked row instead of blocking on it, which
  removes the pile-up the deadlock forms around. Whether it *eliminates* the deadlock or only
  narrows the window must be answered by running the reproduction — and stated plainly either
  way. It does not replace items 1-3: a narrower window is still a window.
- **Stop sweeping on every `processNext()`.** Run `reclaimStaleReservations()` at most once per
  N seconds per process (and always on `--once`, so that path keeps its spec-033 correctness).
  This directly removes the unbounded `UPDATE` that concurrent claims were contending with.

### 5. Deadlocks become visible

A deadlock — retried or final — is logged through Monolog with the job id, queue, attempt and
which operation hit it. Today it is only ever visible as text inside `last_error`.

## Functional requirements

1. FR1 — Handler failure and result-recording failure take different code paths.
2. FR2 — A successful handler whose completion update fails never leaves the job in `pending`.
3. FR3 — A deadlock-aware retry helper retries only on `40001`/`1213` (plus `1205` if the
   implementation decides so), bounded, with backoff.
4. FR4 — `handle()` is never inside a retried unit.
5. FR5 — The claim transaction, the completion update, `recordFailure()`'s updates and the sweep
   are each individually protected.
6. FR6 — When the completion update exhausts its retries, the job ends `failed` with
   `failed_at` set and a `last_error` stating the handler already ran.
7. FR7 — Every deadlock, retried or final, produces a Monolog entry containing job id, queue and
   the operation.
8. FR8 — The sweep runs at most once per configured interval per process, and unconditionally
   under `--once`.
9. FR9 — Spec 008's retry/backoff/`max_attempts` and spec 033's stale recovery behave exactly as
   before for all non-deadlock paths, evidenced by the existing unit tests passing unchanged.
10. FR10 — `ConcurrencyTest`'s `markTestIncomplete()` for this defect is removed.

## Non-functional requirements

- **No new Composer dependency.**
- **No schema change** unless the investigation shows one is genuinely required — the current
  columns appear sufficient, and this must be confirmed rather than assumed.
- The retry must be bounded so a worker cannot spin indefinitely on a contended row.
- No secret or payload content in the new log lines (same rule spec 033 applied).

## User flows

Not applicable as a user-facing flow. The operator-visible change: a job that previously
reappeared and printed a second ticket now either completes once, or shows up in
`bin/jobs-status` as `failed` with an explicit "handler already ran" message.

## API changes

Not applicable — no endpoint is added or changed.

## Data model and migrations

Expected to be **not applicable**: `status`, `last_error`, `failed_at` and `completed_at`
already carry everything the proposed end states need. The implementation must confirm this and,
if a column really is required, add it through `common/migrations/` per `CLAUDE.md` — never ad
hoc.

## Architecture and affected components

- `src/Services/JobService.php` — the split `try`, the retry helper, the sweep interval, logging.
- `bin/worker` — only if the sweep interval needs a setting; prefer not to change it.
- `src/Settings.php` — a sweep-interval accessor, if one is introduced.
- `tests/Integration/ConcurrencyTest.php` — remove `markTestIncomplete()`, add the regression
  test.
- `tests/Unit/JobServiceTest.php` — extend for the split paths that *can* be tested on SQLite
  (e.g. a completion update that throws, simulated by a failing connection or a mocked model).
- **Not** `src/Jobs/PrintOrderJob.php` — the handler contract does not change.

## Security considerations

Not applicable in the authentication/authorization sense — no endpoint or role is touched. The
one discipline carried over: new log lines must contain job identifiers and error text only,
never the payload, which can reference order data.

## Backward compatibility

- **Behavior change, intended**: a job whose completion could not be recorded now ends `failed`
  instead of `pending`. That is the fix, and it is visible in `bin/jobs-status`.
- Existing jobs in any state are unaffected; no migration, no backfill.
- `SKIP LOCKED` changes claim semantics under contention only: with a single worker — Community's
  default — behavior is identical.
- The handler contract (`handle(array $data, ?Job $job = null)`) is unchanged, so
  `PrintOrderJob` and any future job need no edit.

## Acceptance criteria

- AC1 — A completion update that fails with a deadlock leaves the job `failed`, `failed_at` set,
  `last_error` stating the handler already ran, and **not** `pending`.
- AC2 — After a handler succeeds, no code path returns the job to `pending`.
- AC3 — The retry helper retries a simulated `40001` the configured number of times and then
  gives up, rather than looping.
- AC4 — A handler that throws still follows spec 008's semantics exactly: `attempts`
  incremented, backoff applied, `pending` while attempts remain, `failed` at `max_attempts`.
- AC5 — `tests/Unit/JobServiceTest.php` passes **unchanged** for all pre-existing cases.
- AC6 — `ConcurrencyTest::testTwoWorkersCannotClaimTheSameJob` passes without
  `markTestIncomplete()`, over at least ten consecutive runs.
- AC7 — `jobs.attempts` is still exactly `1` after four concurrent workers contend for one job.
- AC8 — A deadlock produces a log line in `logs/app.log` containing the job id and the operation.
- AC9 — `vendor/bin/phpstan analyse` and `php-cs-fixer --dry-run` both exit `0`.
- AC10 — The full suite (Smoke + Unit + Integration) passes with no `Incomplete`.

## Implementation plan

1. Reproduce the deadlock deterministically if possible (see Testing strategy), so the fix can
   be shown to change something rather than assumed to.
2. Add the deadlock-aware retry helper, with unit tests for its own behavior (AC3).
3. Split `processNext()`'s `try`; give result-recording its own path (FR1, FR2).
4. Wrap the claim transaction, completion update, `recordFailure()` updates and sweep (FR5).
5. Implement the terminal state and its logging (FR6, FR7).
6. Apply `SKIP LOCKED`; **measure** whether the deadlock still reproduces and record the answer.
7. Throttle the sweep (FR8).
8. Remove `markTestIncomplete()` and add the regression test; run it repeatedly for AC6.
9. PHPStan, PHP-CS-Fixer, full suite; `act` locally before pushing.

## Testing and validation strategy

**Correction, for the third spec running:** the `/spec-plan` skill's instruction to state that
this project has no automated test infrastructure is stale. PHPUnit (spec 004), GitHub Actions
CI (spec 005), PHPStan and PHP-CS-Fixer (spec 034) and a MySQL-backed integration suite (spec
035) all exist. `specs/000-project-baseline.md:143` was corrected on 2026-09-03. The skill file
itself is overdue a fix.

- **The retry helper is unit-testable** on SQLite by throwing a synthetic `PDOException` with
  SQLSTATE `40001` — that covers AC3 deterministically without needing a real deadlock.
- **AC1/AC2 are testable without a real deadlock** by making the completion update fail on
  demand (a mocked model or an intentionally broken connection), which is better than waiting
  for contention: it exercises the exact interleaving every run.
- **AC6/AC7 need real MySQL and real contention**, and here an honest limitation applies: the
  four-worker race is **not deterministic**. It reproduced reliably enough to find the bug, but a
  green run does not prove the deadlock cannot happen — it proves it did not happen that time.
  That is why AC6 demands ten consecutive runs, and why AC1/AC2 are tested by direct simulation
  rather than relying on the race.
- **If a deterministic MySQL reproduction proves impossible**, say so in the implementation log
  and state precisely what the test does prove, rather than implying stronger coverage.
- Docker must be running; `act` should be used before pushing (`.github/workflows/ci.yml`).

## Rollout and rollback

Rollout: merge and restart the `print-worker` service so it runs the new code. No schema change,
no data migration, nothing to backfill.

Rollback: revert the commit and restart the worker. The previous behavior returns, including the
defect.

## Task checklist

- [x] 1. Reproduction attempt, deterministic if possible — achieved by simulating the failing
  UPDATE rather than racing for a real deadlock (see log entry 1)
- [x] 2. Deadlock-aware retry helper + its unit tests
- [x] 3. Split `processNext()`'s try; separate result-recording path
- [x] 4. Protect claim, completion, failure and sweep statements
- [x] 5. Terminal state for unrecordable completion + logging
- [x] 6. `SKIP LOCKED` measured — and **reverted**, see log entry 3
- [x] 7. Sweep throttling
- [x] 8. Remove `markTestIncomplete()`; regression test; ten consecutive runs
- [x] 9. PHPStan, PHP-CS-Fixer, full suite

## Implementation log

- **2026-09-24 — 1. The deadlock was made testable by simulating it, not by racing for it.**
  The open question asked whether a deterministic reproduction was possible. It is, for the part
  that matters: a `Job` subclass whose `update()` throws
  `SQLSTATE[40001] … 1213 Deadlock found` when the attributes carry `status = completed`
  reproduces the exact interleaving on every run, on SQLite, in milliseconds. That covers AC1,
  AC2 and AC3 deterministically. What remains non-deterministic is only whether MySQL *chooses*
  to deadlock under contention — which is the engine's business, not the behaviour under test.
- **2026-09-24 — 2. The `try` split is the actual fix.** `processNext()` now has two phases:
  phase 1 resolves and runs the handler (failure → `recordFailure()`, unchanged spec 008
  semantics, and an early `return`), phase 2 records the result and can never re-queue. A
  successful handler leaves no path back to `pending`.
- **2026-09-24 — 3. ⚠ `SKIP LOCKED` was NOT what fixed it, and was reverted.** It was
  implemented first and the deadlock stopped reproducing — but two changes had landed together,
  so the credit was ambiguous. Isolating it: with `SKIP LOCKED` reverted to plain
  `lockForUpdate()` and only the sweep throttling in place, five consecutive four-worker runs
  passed with **zero retry warnings logged** — i.e. no deadlock occurred at all. The sweep's two
  unbounded `UPDATE`s on every `processNext()` were the deadlock partner, exactly as the Problem
  section hypothesised; throttling them removed the contention.
  `SKIP LOCKED` was therefore reverted: it is not needed for the fix, spec 033 deliberately chose
  `lockForUpdate()`, and Community runs a single worker so the "losers skip instead of blocking"
  benefit is theoretical today. Changing locking semantics without need would have been an
  unrequested change. The measurement is recorded in a comment at the call site so the next
  person does not redo it.
- **2026-09-24 — 4. The retry helper stayed even though nothing now triggers it.** With the
  contention gone, no retry fired in twenty runs. It is kept deliberately: the deadlock window is
  narrowed, not proven impossible (the sweep still runs every 10s, and a second worker would
  reintroduce contention), and the helper is what makes the failure survivable rather than fatal.
  Its own behaviour is unit-tested, so it is not dead untested code.
- **2026-09-24 — 5. Sweep throttling preserves spec 033's `--once` correctness by construction.**
  `$lastSweepAt` starts at `0.0`, meaning "never swept in this process", so the first
  `processNext()` in any process always sweeps. `bin/worker --once` is a fresh process, so it
  behaves exactly as before. No parameter or signature change was needed.
- **2026-09-24 — 6. No schema change was required**, as the spec anticipated but did not assume.
  `status`, `last_error`, `failed_at` and `completed_at` (migration `016`) already express every
  state the new paths need.

## Validation evidence

All commands run on 2026-09-24 inside the containers, `MYSQL_DATABASE_TEST=restaurant_test`.

- **AC1 + AC6** — `JobServiceTest::testCompletionThatCannotBeRecordedParksTheJobInsteadOfRequeueing`
  passes. Also exercised end-to-end against the real logger with a simulated deadlock:
  `status final: failed`, `last_error: "O handler JÁ FOI EXECUTADO, mas o resultado não pôde ser
  gravado: SQLSTATE[40001]…"`, and `failed_at` set.
- **AC2** — the same test asserts `STATUS_PENDING` appears in **none** of the update attempts
  after a successful handler.
- **AC3** — `testRetryHelperRetriesDeadlocksAndThenGivesUp` asserts exactly `4` calls
  (1 attempt + 3 retries) and that the exception is rethrown;
  `testRetryHelperDoesNotRetryOrdinaryErrors` asserts a non-transient error is attempted once.
  `testCompletionIsRetriedBeforeGivingUp` asserts the completion update itself is retried 4 times.
- **AC4 + AC5** — the pre-existing unit tests were **not modified**. `--testsuite Unit` →
  `OK (132 tests, 230 assertions)` before the new tests were added, `OK (136 tests, 239
  assertions)` after. Spec 008's retry/backoff and spec 033's stale recovery assertions all still
  pass as written.
- **AC6 (ten runs)** — `--testsuite Integration --filter ConcurrencyTest`, ten consecutive runs:
  `OK: 10 / falhas ou incompletos: 0`. Then ten consecutive runs of the **whole** integration
  suite: `limpo: 10 / com problema: 0`.
- **AC7** — `ConcurrencyTest::testTwoWorkersCannotClaimTheSameJob` still asserts
  `jobs.attempts === 1` after four concurrent workers, and now additionally asserts the job is
  neither `pending` nor anything other than `completed`.
- **AC8** — simulated deadlock against the real app logger produced, in `logs/app.log`:
  three `job.WARNING: Erro transitório de banco; tentando novamente` lines carrying
  `{"job_id":90001,"queue":"spec037","operation":"complete","attempt":1..3,"max":3,…}`, followed
  by `job.ERROR: Job concluído mas não foi possível gravar o resultado`. Job id, queue and
  operation present; no payload logged.
- **AC9** — `vendor/bin/phpstan analyse` → `[OK] No errors`;
  `php-cs-fixer --dry-run` → `Found 0 of 78 files that can be fixed`.
- **AC10** — full suite → `OK (163 tests, 324 assertions)`, **no `Incomplete`**. Before this
  spec it was `Tests: 159, Assertions: 313, Incomplete: 1`.
- **Isolation experiment (the spec's main open question)** — `SKIP LOCKED` removed, sweep
  throttling kept, five consecutive four-worker runs: all `OK (1 test, 10 assertions)`, and
  `grep -c "Erro transitório de banco" logs/app.log` → `0`. No deadlock occurred, so the sweep
  throttling is what removed it; `SKIP LOCKED` is not the fix.

**Not validated, stated rather than implied:**

- **The deadlock is no longer reproducible, so the fix's deadlock path is only exercised by
  simulation.** Twenty runs produced zero real deadlocks. That is the desired outcome, but it
  means the retry helper and the parking path are proven against a synthetic `PDOException`
  carrying the same SQLSTATE, not against MySQL's own. The simulation matches what MySQL threw
  when it was reproducible (the message is copied from the real failure recorded in spec 035).
- **`1205` (lock wait timeout) is in the retryable list but was never observed.** It was added
  because it is the same class of transient contention error; no run produced one, so its
  handling is untested beyond the string match.
- **Multi-worker operation was not load-tested.** Community runs one `print-worker`; the
  contention tested here is four short-lived processes, not sustained parallel workers.

## Open questions

Resolved during implementation:

- **Does `SKIP LOCKED` remove the deadlock or merely narrow it?** Neither — it was not the cause
  of the fix at all. The sweep throttling was. `SKIP LOCKED` was reverted (log entry 3).
- **Should `1205` be retried alongside `1213`?** Yes, included — same class of transient
  contention failure — while recording that it was never actually observed.
- **Is the sweep interval worth a `Settings` entry?** No. A `SWEEP_INTERVAL_SECONDS = 10.0`
  constant is sufficient; no requirement asked for it to vary per deployment, and spec 011's
  pattern is for things an operator genuinely needs to change.
- **Can the deadlock be reproduced deterministically?** Yes for the behaviour that matters, by
  simulating the failing UPDATE (log entry 1). Not for MySQL's own choice to deadlock, which is
  why AC6 demanded ten runs rather than one.
