# Spec 008 — Reliable print job flow (worker + retry + observability)

## Metadata

- Status: Implemented
- Created: 2026-08-30
- Updated: 2026-08-30
- Owner: opencode
- Related issue: Print queue investigation (orders not being printed)
- Related branch: master

## Context

Orders are not printing. Investigation found the printer network path is currently
unreachable, but more importantly the software print pipeline has defects that make it
unreliable even when the network is healthy. This spec covers the software fixes that make
the flow robust; printer network reachability is tracked separately.

## Problem

1. **No worker runs on `docker compose up -d`.** `docker-compose.yml` defines only `db`
   and `web`. Print jobs dispatched to the `print` queue are therefore never consumed.
   Confirmed live: 13 `print` jobs sat in `jobs` with `attempts=0`, `reserved_at=NULL`.
2. **`PrintService::printOrder()` swallows exceptions.** It wraps printing in
   `try { ... } catch (\Throwable $e) { $logger->error(...); }` and never rethrows
   (`src/Services/PrintService.php:48-58`). Confirmed live: running the worker on one job
   printed `job.ERROR: ... Connection timed out` yet the worker reported "✓ Job processed."
   and the job was **deleted** — a failed print with no retry.
3. **`PrintOrderJob` builds a throwaway logger** writing to PHP error log instead of the
   application log file, and has no job/attempt context for observability.
4. **Observability is poor**: failures, retries, and permanent failures are not
   distinguishable, and log lines lack printer/attempt/job context.

## Goals

- Print failures propagate to `JobService` so it can retry and then keep failed jobs for
  inspection.
- A worker runs automatically so the `print` queue is consumed.
- Order creation never fails because the printer is offline.
- Logging distinguishes connection refused / timeout / retry / max-attempts / success.

## Non-goals

- Not changing the printer IP, not changing ESC/POS library, not introducing
  Redis/RabbitMQ, not rewriting the whole job system.
- Not fixing the physical reachability of `192.168.0.136:9100` (network/device concern).

## Current behavior

- `src/Services/PrintService.php:39-59` — `printOrder()` catches all `\Throwable` and logs
  only; returns normally; job is treated as success and deleted.
- `src/Services/JobService.php:39-96` — `processNext()` reserves, executes `handle($data)`,
  deletes on success, releases or keeps on exception. Its retry/failed logic is correct
  *once exceptions actually reach it*: `attempts < max_attempts` → release with backoff;
  `attempts >= max_attempts` → keep in DB (not reprocessed because the reserve query
  filters `attempts < max_attempts`). The flaw is upstream: exceptions never get here.
- `src/Jobs/PrintOrderJob.php` — builds its own `Logger` with `ErrorLogHandler`.
- `docker-compose.yml` — no worker service.
- `src/Services/OrderService.php:28-44` — order creation and job dispatch are already
  decoupled (dispatch is async), so order creation is not coupled to printing success.

## Proposed behavior

- `PrintService::printOrder()` logs the error then **rethrows** so the failure travels to
  `JobService`.
- `PrintOrderJob` logs with application-consistent context; `JobService` passes job
  context (job id, attempt, max_attempts) to the handler so logs read like
  `Print job #123 / order #456 printer=192.168.0.136:9100 attempt=2/3 error="..."`.
- A `print-worker` service is added to `docker-compose.yml` running `php bin/worker print`
  indefinitely, reusing the web volumes/env/Docker image, restarting automatically, no
  HTTP port.
- Failed (max-attempts) jobs stay in the `jobs` table for inspection.

## Functional requirements

- FR1: `printOrder()` rethrows the caught `\Throwable` after logging.
- FR2: `JobService` increments `attempts` on each attempt.
- FR3: `JobService` keeps (does not delete) a job when the handler throws.
- FR4: `JobService` releases a job for retry with backoff while `attempts < max_attempts`.
- FR5: `JobService` leaves a job in the DB, not reprocessed, once `attempts >= max_attempts`.
- FR6: `JobService` deletes a job on handler success.
- FR7: `docker compose up -d` starts a worker that consumes the `print` queue.
- FR8: Order creation succeeds even when the printer/network is down.

## Non-functional requirements

- Observability: log lines must distinguish success, connection refused, timeout, retry,
  and max-attempts, and include job/order/printer/attempt context without secrets.
- No new hard dependencies; keep Monolog + current architecture.

## User flows

Cashier creates order → `OrderService` persists order then dispatches `print` job
(asynchronously) → worker picks it up → `PrintOrderJob` → `PrintService`; on success job
deleted; on failure `JobService` retries with backoff, then keeps the job after
`max_attempts`. Order remains valid throughout.

## API changes

Not applicable. No endpoint signatures change. `POST /api/admin/settings/test-print`
behavior is unchanged.

## Data model and migrations

Not applicable. Uses existing `jobs` table; no schema change.

## Architecture and affected components

- `src/Services/PrintService.php` — rethrow after logging.
- `src/Services/JobService.php` — pass job context to handler; clearer failure logging.
- `src/Jobs/PrintOrderJob.php` — accept job context; log with context.
- `docker-compose.yml` — add `print-worker` service.
- `tests/Unit/` — new unit tests for PrintService-rethrow and JobService retry semantics.

## Security considerations

No credentials or secrets logged. Printer IP/port are config values, not secrets; still,
log lines only include printer address as configured, matching existing behavior.

## Backward compatibility

`handle(array $data)` retains the same first parameter; an optional second arg is added
and only used for richer logging, so existing call sites (and the `OrderServiceTest` mock
of `dispatch`) are unaffected.

## Acceptance criteria

- AC1: `PrintService::printOrder()` with a failing connector throws; exception propagates
  to the caller (unit test).
- AC2: `JobService` does not delete a job whose handler throws, and its `attempts`
  increments (unit test).
- AC3: `JobService` releases a job with `reserved_at=NULL` and a future `available_at` when
  `attempts < max_attempts` after a failure (unit test).
- AC4: After `attempts >= max_attempts` a failed job stays in the DB and `processNext`
  does not select it again (unit test).
- AC5: On handler success the job is deleted (unit test).
- AC6: `docker-compose.yml` includes a `print-worker` service running `php bin/worker print`
  with `restart: unless-stopped`, no host HTTP port, reusing web volumes/env.
- AC7: Existing PHPUnit tests pass and changed PHP files pass `php -l`.

## Implementation plan

1. Modify `PrintService::printOrder()` to log then rethrow.
2. Add job/attempt context to `JobService::processNext()` handler invocation.
3. Update `PrintOrderJob::handle()` to accept and use job context for logging.
4. Add `print-worker` service to `docker-compose.yml`.
5. Add unit tests covering retry/failure semantics; run full suite and `php -l`.

## Testing and validation strategy

Unit tests (mocked connector/printer, no physical printer). Manual integration with the
real printer is only done if/when network reachability is restored. Validation evidence
will record actual outputs.

## Rollout and rollback

`docker compose up -d --build` (or recreate) to start the worker. Revert: remove the
`print-worker` service from `docker-compose.yml`; code changes are small and individually
reversible.

## Open questions

None.

## Task checklist

- [x] PrintService rethrow (log then `throw $e` in `printOrder()`; added testable connector factory)
- [x] JobService context + logging (`handle($data, $job)`; `logJobFailure()` for retry/permanent failure)
- [x] PrintOrderJob context + logging (logger writes to app log file + error log; passes job context)
- [x] docker-compose print-worker service (`print-worker`, `php bin/worker print`, `restart: unless-stopped`, shared vendor mount)
- [x] bin/worker guarded for missing pcntl (container lacks pcntl; worker would otherwise fatal in continuous mode)
- [x] Unit tests (`PrintServiceTest`, `JobServiceTest`: 6 retry/failure scenarios)
- [x] Run PHPUnit + php -l (14 tests / 33 assertions pass)

## Implementation log

- 2026-08-30: Investigation. Found no worker running; 13 stuck `print` jobs
  (`attempts=0`). Confirmed `PrintService` swallows the exception and a failed print job
  was deleted (job id 1). DB settings correct (`printer_ip=192.168.0.136`,
  `printer_port=9100`). Connectivity to `192.168.0.136:9100` times out from both the
  container and the Windows host — a network/device issue, out of scope for software.
- 2026-08-30: Implemented the four software fixes above. Also found the Docker image's
  baked `vendor/` omits `mike42/escpos-php`, so a fresh worker can't resolve the connector
  even after fixing logic; `web` only had it via a runtime `composer install` in its
  writable layer. Because a full image rebuild is blocked by low disk space on C: (~9.8 GB
  free; BuildKit fails `tls: bad record MAC` writing layers), per user decision we used the
  low-disk fix: copied `web`'s proven working `vendor/` (~14.8 MB) to the host and mounted
  `./vendor` into both `web` and `print-worker`. Both now resolve mike42.

## Validation evidence

- `docker compose exec -T web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)` after
  all changes (was 8 tests before; added 6 new).
- `php -l` clean on all changed PHP files (`src/Services/PrintService.php`,
  `src/Services/JobService.php`, `src/Jobs/PrintOrderJob.php`, `bin/worker`,
  `tests/Unit/PrintServiceTest.php`, `tests/Unit/JobServiceTest.php`).
- Live worker run (printer deliberately offline): job went
  attempt 1/3 → retry(2s) → attempt 2/3 → retry(4s) → attempt 3/3 → kept in DB
  (`attempts=3`, `reserved_at=NULL`), log lines include
  `print_job=15 attempt=1/3 order=31 printer=192.168.0.136:9100 error="...Connection timed out"`.
- DB check after run: all failed jobs remain in `jobs` (`attempts=3`, not deleted); 0 jobs
  awaiting processing. `GET /api/menu` returns 200 after container recreation.
- Physical printer result: `192.168.0.136:9100` — TCP connect times out
  (errno 110) from the container AND from the Windows host; ping also fails. This is a
  network/device reachability issue, not a software defect; the software flow is verified
  up to the (unreachable) printer via the mocked unit tests and the live retry/keep-failed
  behaviour.

