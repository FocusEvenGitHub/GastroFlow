<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;
use App\Settings;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class JobService
{
    /** Keeps a stack trace or a verbose driver message from overflowing the column. */
    private const LAST_ERROR_MAX_LENGTH = 1000;

    /**
     * MySQL conditions that are transient under contention and safe to retry as a unit:
     * 40001/1213 deadlock, 1205 lock wait timeout. Found by spec 035's concurrency test.
     */
    private const RETRYABLE_DB_ERRORS = ['40001', '1213', '1205'];

    /** Bounded, so a worker can never spin forever on a contended row. */
    private const MAX_DB_RETRIES = 3;

    /** How often a single process re-runs the stale-reservation sweep. */
    private const SWEEP_INTERVAL_SECONDS = 10.0;

    /** 0.0 means "never swept in this process", so the first call always sweeps. */
    private float $lastSweepAt = 0.0;

    /**
     * Both dependencies are optional so `new JobService()` keeps working in bin/ scripts,
     * while the DI container (which already defines LoggerInterface and Settings) autowires
     * them for HTTP requests and tests can inject a NullLogger.
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
        private ?Settings $settings = null,
    ) {
    }

    /**
     * Dispatch a job into the queue.
     *
     * @param string $queue   Queue name (e.g. 'print', 'default')
     * @param string $handler Fully-qualified class name of the handler
     * @param array  $data    Payload data
     * @param int    $delay   Seconds to delay execution (0 = immediate)
     */
    public function dispatch(string $queue, string $handler, array $data, int $delay = 0): Job
    {
        return Job::create([
            'queue'        => $queue,
            'payload'      => json_encode([
                'handler' => $handler,
                'data'    => $data,
            ]),
            'status'       => Job::STATUS_PENDING,
            'attempts'     => 0,
            'max_attempts' => 3,
            'available_at' => Carbon::now()->addSeconds($delay),
        ]);
    }

    /**
     * Process the next available job from a given queue.
     * Returns true if a job was processed, false if no job is available.
     */
    public function processNext(string $queue = 'default'): bool
    {
        $this->sweepIfDue($queue);

        $timeout = $this->settings()->getQueueReservationTimeout();

        // Reserve the next available job (atomic SELECT … FOR UPDATE)
        $job = $this->retryOnTransientDbError('claim', function () use ($queue, $timeout) {
            return DB::transaction(function () use ($queue, $timeout) {
                $job = Job::where('queue', $queue)
                    ->where('status', Job::STATUS_PENDING)
                    ->where('available_at', '<=', Carbon::now())
                    ->where('attempts', '<', DB::raw('max_attempts'))
                    ->orderBy('id')
                    // Kept as plain FOR UPDATE, as spec 033 chose. SKIP LOCKED was tried and
                    // measured during spec 037: with the sweep throttled, the deadlock stops
                    // reproducing either way (5 runs, zero retries logged), so SKIP LOCKED is
                    // not what fixes it and changing the locking semantics was not warranted.
                    ->lockForUpdate()
                    ->first();

                if (!$job) {
                    return null;
                }

                $now = Carbon::now();
                $job->update([
                    'status'         => Job::STATUS_RESERVED,
                    'reserved_at'    => $now,
                    'reserved_until' => $now->copy()->addSeconds($timeout),
                    'attempts'       => $job->attempts + 1,
                ]);

                return $job;
            });
        });

        if (!$job) {
            return false;
        }

        // Phase 1 — do the work. A failure here is the job's failure, and goes through the
        // normal retry/backoff path from spec 008.
        try {
            $payload = json_decode($job->payload, true);
            $handler = $payload['handler'] ?? null;
            $data    = $payload['data'] ?? [];

            // An unresolvable handler used to fall through to $job->delete(), destroying the
            // job as if it had succeeded. Throwing puts it on the normal failure path instead.
            if (!is_string($handler) || $handler === '' || !class_exists($handler)) {
                throw new \RuntimeException(sprintf(
                    'Handler do job não pôde ser resolvido: "%s".',
                    is_string($handler) ? $handler : gettype($handler)
                ));
            }

            $instance = new $handler();
            $instance->handle($data, $job);
        } catch (\Throwable $e) {
            $this->recordFailure($job, $e);

            return true;
        }

        // Phase 2 — the work is DONE. From here the job must never go back to 'pending':
        // re-queueing it would run the handler a second time. Recording the result is a
        // separate failure with a separate answer (spec 037).
        $this->recordCompletion($job);

        return true;
    }

    /**
     * Record a finished job. Never re-queues: the handler already ran.
     */
    private function recordCompletion(Job $job): void
    {
        try {
            $this->retryOnTransientDbError('complete', function () use ($job) {
                $job->update([
                    'status'         => Job::STATUS_COMPLETED,
                    'completed_at'   => Carbon::now(),
                    'reserved_at'    => null,
                    'reserved_until' => null,
                    'last_error'     => null,
                ]);
            }, $job);

            return;
        } catch (\Throwable $e) {
            $this->markCompletionUnrecordable($job, $e);
        }
    }

    /**
     * The handler ran but its result could not be written, even after retries.
     *
     * The job is parked in 'failed' — terminal, never re-claimed — rather than returned to
     * the queue. That is deliberately an alarming state: the real situation is "work done,
     * bookkeeping lost", and an operator should look at it. Leaving it 'reserved' would let
     * the stale-reservation sweep re-run it, which is the exact defect this spec fixes.
     */
    private function markCompletionUnrecordable(Job $job, \Throwable $e): void
    {
        $this->logger()->error('Job concluído mas não foi possível gravar o resultado', [
            'job_id'    => $job->id,
            'queue'     => $job->queue,
            'attempt'   => $job->attempts,
            'operation' => 'complete',
            'error'     => $e->getMessage(),
        ]);

        // Best effort: if even this cannot be written, the reservation eventually expires and
        // the sweep marks it failed (spec 033) — still not re-run, because attempts is spent
        // only when max_attempts allows. Never let bookkeeping kill the worker.
        try {
            $this->retryOnTransientDbError('complete-fallback', function () use ($job, $e) {
                $job->update([
                    'status'         => Job::STATUS_FAILED,
                    'failed_at'      => Carbon::now(),
                    'reserved_at'    => null,
                    'reserved_until' => null,
                    'last_error'     => $this->truncateError(
                        'O handler JÁ FOI EXECUTADO, mas o resultado não pôde ser gravado: '
                        . $e->getMessage()
                        . ' — o job NÃO foi recolocado na fila para não repetir o trabalho.'
                    ),
                ]);
            }, $job);
        } catch (\Throwable $inner) {
            $this->logger()->error('Falha ao registrar a conclusão não gravável do job', [
                'job_id' => $job->id,
                'queue'  => $job->queue,
                'error'  => $inner->getMessage(),
            ]);
        }
    }

    /**
     * Run a database operation, retrying the transient contention errors MySQL raises when
     * several workers touch the same rows.
     *
     * Deliberately never wraps a job handler: retrying a unit that contains the handler is
     * precisely how work gets done twice (spec 037).
     *
     * @template T
     * @param  callable(): T $operation
     * @return T
     */
    private function retryOnTransientDbError(string $name, callable $operation, ?Job $job = null)
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $attempt++;

                if (!$this->isRetryableDbError($e) || $attempt > self::MAX_DB_RETRIES) {
                    throw $e;
                }

                $this->logger()->warning('Erro transitório de banco; tentando novamente', [
                    'job_id'    => $job?->id,
                    'queue'     => $job?->queue,
                    'operation' => $name,
                    'attempt'   => $attempt,
                    'max'       => self::MAX_DB_RETRIES,
                    'error'     => $e->getMessage(),
                ]);

                // Short backoff with jitter so retrying workers do not collide again in step.
                usleep((int) ((2 ** $attempt) * 10_000 + random_int(0, 10_000)));
            }
        }
    }

    private function isRetryableDbError(\Throwable $e): bool
    {
        $haystack = $e->getMessage() . '|' . (string) $e->getCode();

        foreach (self::RETRYABLE_DB_ERRORS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sweep is two unbounded UPDATEs over the same index ranges concurrent claims lock,
     * which is what the deadlock formed around. Spec 033 ran it on every call for correctness
     * under --once; throttling by time keeps that (a fresh process has never swept, so the
     * first call always does) while removing it from the hot loop.
     */
    private function sweepIfDue(string $queue): void
    {
        $now = microtime(true);

        if ($this->lastSweepAt !== 0.0 && ($now - $this->lastSweepAt) < self::SWEEP_INTERVAL_SECONDS) {
            return;
        }

        $this->lastSweepAt = $now;
        $this->reclaimStaleReservations($queue);
    }

    /**
     * Recover jobs whose worker died while holding the reservation.
     *
     * Returns how many rows were recovered (released + failed).
     */
    public function reclaimStaleReservations(string $queue = 'default'): int
    {
        $now = Carbon::now();

        // No attempts left: mark it failed, otherwise it would sit 'reserved' forever —
        // the claim query excludes it, so nothing else would ever touch it again.
        $failed = $this->retryOnTransientDbError('sweep-failed', fn () => Job::where('queue', $queue)
            ->where('status', Job::STATUS_RESERVED)
            ->where('reserved_until', '<', $now)
            ->whereColumn('attempts', '>=', 'max_attempts')
            ->update([
                'status'         => Job::STATUS_FAILED,
                'reserved_at'    => null,
                'reserved_until' => null,
                'failed_at'      => $now,
                'last_error'     => 'Reserva expirada: o worker provavelmente foi encerrado durante a execução.',
            ]));

        // Attempts left: back to the queue. The attempt consumed at claim time is NOT
        // refunded, so a job that reliably kills its worker still exhausts max_attempts
        // instead of looping forever.
        $released = $this->retryOnTransientDbError('sweep-released', fn () => Job::where('queue', $queue)
            ->where('status', Job::STATUS_RESERVED)
            ->where('reserved_until', '<', $now)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->update([
                'status'         => Job::STATUS_PENDING,
                'reserved_at'    => null,
                'reserved_until' => null,
                'available_at'   => $now,
            ]));

        return $failed + $released;
    }

    /**
     * Delete completed jobs older than $days (defaults to QUEUE_RETENTION_DAYS).
     * Failed jobs are never pruned here — they are the diagnostic record.
     */
    public function pruneCompleted(?int $days = null): int
    {
        $days = $days ?? $this->settings()->getQueueRetentionDays();

        return Job::where('status', Job::STATUS_COMPLETED)
            ->where('completed_at', '<', Carbon::now()->subDays($days))
            ->delete();
    }

    /**
     * Jobs an operator needs to look at: permanently failed ones, plus reservations
     * that have already expired and not yet been swept.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Job>
     */
    public function getJobsNeedingAttention(?string $queue = null)
    {
        $now = Carbon::now();

        $query = Job::where(function ($q) use ($now) {
            $q->where('status', Job::STATUS_FAILED)
                ->orWhere(function ($stale) use ($now) {
                    $stale->where('status', Job::STATUS_RESERVED)
                        ->where('reserved_until', '<', $now);
                });
        });

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        return $query->orderBy('id')->get();
    }

    private function recordFailure(Job $job, \Throwable $e): void
    {
        $error = $this->truncateError($e->getMessage());

        // If max attempts reached, mark it failed and keep it for inspection.
        // Otherwise release it so it can be retried.
        if ($job->attempts >= $job->max_attempts) {
            $this->retryOnTransientDbError('fail-permanent', fn () => $job->update([
                'status'         => Job::STATUS_FAILED,
                'reserved_at'    => null,
                'reserved_until' => null,
                'failed_at'      => Carbon::now(),
                'last_error'     => $error,
            ]), $job);
            $this->logJobFailure($job, $e, "Falha permanente (max_attempts={$job->max_attempts} atingido)");

            return;
        }

        // Release with a small delay (exponential backoff)
        $backoff = (int) pow(2, $job->attempts);
        $this->retryOnTransientDbError('fail-release', fn () => $job->update([
            'status'         => Job::STATUS_PENDING,
            'reserved_at'    => null,
            'reserved_until' => null,
            'available_at'   => Carbon::now()->addSeconds($backoff),
            'last_error'     => $error,
        ]), $job);
        $this->logJobFailure($job, $e, "Job liberado para retry em {$backoff}s (attempt={$job->attempts}/{$job->max_attempts})");
    }

    private function truncateError(string $message): string
    {
        if (mb_strlen($message) <= self::LAST_ERROR_MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::LAST_ERROR_MAX_LENGTH - 1) . '…';
    }

    /**
     * Failure logging goes through Monolog to the application log file, so job failures
     * show up in the Admin log viewer. The payload is deliberately never logged — it can
     * carry order data.
     */
    private function logJobFailure(Job $job, \Throwable $e, string $context): void
    {
        $this->logger()->error($context, [
            'job_id'       => $job->id,
            'queue'        => $job->queue,
            'attempt'      => $job->attempts,
            'max_attempts' => $job->max_attempts,
            'error'        => $e->getMessage(),
        ]);
    }

    private function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        $settings = $this->settings();
        $logger   = new Logger('job');

        $logDir = $settings->getLogDir();
        if (is_dir($logDir) || @mkdir($logDir, 0755, true) || is_dir($logDir)) {
            $logger->pushHandler(new StreamHandler($settings->getLogFile(), Logger::DEBUG));
        }
        $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

        return $this->logger = $logger;
    }

    /**
     * Process all available jobs in a queue (up to $max jobs).
     */
    public function processAll(string $queue = 'default', int $max = 50): int
    {
        $count = 0;
        while ($count < $max && $this->processNext($queue)) {
            $count++;
        }
        return $count;
    }
}
