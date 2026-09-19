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
        $this->reclaimStaleReservations($queue);

        $timeout = $this->settings()->getQueueReservationTimeout();

        // Reserve the next available job (atomic SELECT … FOR UPDATE)
        $job = DB::transaction(function () use ($queue, $timeout) {
            $job = Job::where('queue', $queue)
                ->where('status', Job::STATUS_PENDING)
                ->where('available_at', '<=', Carbon::now())
                ->where('attempts', '<', DB::raw('max_attempts'))
                ->orderBy('id')
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

        if (!$job) {
            return false;
        }

        // Execute the job
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

            // Success — keep the row as history; pruneCompleted() removes it later.
            $job->update([
                'status'         => Job::STATUS_COMPLETED,
                'completed_at'   => Carbon::now(),
                'reserved_at'    => null,
                'reserved_until' => null,
                'last_error'     => null,
            ]);
        } catch (\Throwable $e) {
            $this->recordFailure($job, $e);
        }

        return true;
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
        $failed = Job::where('queue', $queue)
            ->where('status', Job::STATUS_RESERVED)
            ->where('reserved_until', '<', $now)
            ->whereColumn('attempts', '>=', 'max_attempts')
            ->update([
                'status'         => Job::STATUS_FAILED,
                'reserved_at'    => null,
                'reserved_until' => null,
                'failed_at'      => $now,
                'last_error'     => 'Reserva expirada: o worker provavelmente foi encerrado durante a execução.',
            ]);

        // Attempts left: back to the queue. The attempt consumed at claim time is NOT
        // refunded, so a job that reliably kills its worker still exhausts max_attempts
        // instead of looping forever.
        $released = Job::where('queue', $queue)
            ->where('status', Job::STATUS_RESERVED)
            ->where('reserved_until', '<', $now)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->update([
                'status'         => Job::STATUS_PENDING,
                'reserved_at'    => null,
                'reserved_until' => null,
                'available_at'   => $now,
            ]);

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
            $job->update([
                'status'         => Job::STATUS_FAILED,
                'reserved_at'    => null,
                'reserved_until' => null,
                'failed_at'      => Carbon::now(),
                'last_error'     => $error,
            ]);
            $this->logJobFailure($job, $e, "Falha permanente (max_attempts={$job->max_attempts} atingido)");

            return;
        }

        // Release with a small delay (exponential backoff)
        $backoff = (int) pow(2, $job->attempts);
        $job->update([
            'status'         => Job::STATUS_PENDING,
            'reserved_at'    => null,
            'reserved_until' => null,
            'available_at'   => Carbon::now()->addSeconds($backoff),
            'last_error'     => $error,
        ]);
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
