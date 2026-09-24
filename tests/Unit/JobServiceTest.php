<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Job;
use App\Services\JobService;
use App\Settings;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FailingJob
{
    public function handle(array $data, ?Job $job = null): void
    {
        throw new \RuntimeException('simulated handler failure');
    }
}

class SucceedJob
{
    public function handle(array $data, ?Job $job = null): void
    {
        // no-op
    }
}

/**
 * A Job whose completion UPDATE fails the way MySQL fails under contention.
 *
 * This is how spec 037's criteria are tested deterministically: waiting for a real deadlock
 * would mean waiting for a race, and a green run would only prove it did not happen that time.
 */
class DeadlockingJob extends Job
{
    /** @var list<array<string, mixed>> */
    public array $updates = [];

    public function update(array $attributes = [], array $options = [])
    {
        $this->updates[] = $attributes;

        if (($attributes['status'] ?? null) === Job::STATUS_COMPLETED) {
            throw new \PDOException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'
            );
        }

        return true;
    }
}

/**
 * Captures the reservation fields as they are *while the handler runs*.
 * Values are copied, not the model reference: the success path clears reserved_at /
 * reserved_until on that same object right after handle() returns.
 */
class ProbeJob
{
    public static ?Carbon $reservedAt = null;
    public static ?Carbon $reservedUntil = null;
    public static bool $called = false;

    public function handle(array $data, ?Job $job = null): void
    {
        self::$called        = true;
        self::$reservedAt    = $job?->reserved_at?->copy();
        self::$reservedUntil = $job?->reserved_until?->copy();
    }
}

class JobServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Mirrors common/migrations/007_jobs.sql + 016_job_reliability.sql.
        // Keep in sync with those files, or JobService writes fail with "no such column".
        Db::schema()->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue', 50)->default('default');
            $table->text('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('available_at')->useCurrent();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        ProbeJob::$called        = false;
        ProbeJob::$reservedAt    = null;
        ProbeJob::$reservedUntil = null;
    }

    /** A service that never touches the real log file. */
    private function service(): JobService
    {
        return new JobService(new NullLogger(), new Settings());
    }

    private function makeJob(string $handler, int $attempts, int $maxAttempts, array $overrides = []): Job
    {
        return Job::create(array_merge([
            'queue'        => 'print',
            'payload'      => json_encode(['handler' => $handler, 'data' => []]),
            'status'       => Job::STATUS_PENDING,
            'attempts'     => $attempts,
            'max_attempts' => $maxAttempts,
            'available_at' => Carbon::now()->subMinute(),
        ], $overrides));
    }

    public function testFailingJobIsNotDeletedAndIncrementsAttempts(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\FailingJob', 0, 3);

        $this->assertTrue($this->service()->processNext('print'));

        $fresh = Job::find($job->id);
        $this->assertNotNull($fresh, 'Job must NOT be deleted when the handler throws');
        $this->assertSame(1, $fresh->attempts);
    }

    /** AC4 */
    public function testFailingJobIsReleasedForRetryBeforeMaxAttempts(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\FailingJob', 0, 3);

        $this->service()->processNext('print');

        $fresh = Job::find($job->id);
        $this->assertSame(Job::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->reserved_at, 'Job must be released (not reserved) for retry');
        $this->assertNull($fresh->reserved_until);
        $this->assertNull($fresh->failed_at, 'A retryable failure is not a permanent failure');
        $this->assertSame('simulated handler failure', $fresh->last_error);
        $this->assertTrue(
            $fresh->available_at->greaterThan(Carbon::now()),
            'Job must have a future available_at for retry backoff'
        );
    }

    /** AC5 */
    public function testMaxAttemptsReachedMarksJobFailedAndNotReprocessed(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\FailingJob', 2, 3);

        $service = $this->service();
        $this->assertTrue($service->processNext('print'));

        $fresh = Job::find($job->id);
        $this->assertNotNull($fresh, 'Job must remain in the DB for inspection after max attempts');
        $this->assertSame(Job::STATUS_FAILED, $fresh->status);
        $this->assertSame(3, $fresh->attempts);
        $this->assertNull($fresh->reserved_at);
        $this->assertNotNull($fresh->failed_at);
        $this->assertSame('simulated handler failure', $fresh->last_error);

        // It must not be selected/processed again.
        $this->assertFalse($service->processNext('print'));
    }

    /** AC3 — replaces the old testSuccessfulJobIsDeleted: success now keeps the row. */
    public function testSuccessfulJobIsKeptAsCompleted(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\SucceedJob', 0, 3);

        $this->assertTrue($this->service()->processNext('print'));

        $fresh = Job::find($job->id);
        $this->assertNotNull($fresh, 'Successful jobs are kept as history, not deleted');
        $this->assertSame(Job::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->reserved_at);
        $this->assertNull($fresh->reserved_until);
        $this->assertNull($fresh->last_error);
    }

    public function testProcessNextReturnsFalseWhenNoJobsAvailable(): void
    {
        $this->assertFalse($this->service()->processNext('print'));
    }

    /** AC1 */
    public function testStaleReservationWithAttemptsLeftGoesBackToPending(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\SucceedJob', 1, 3, [
            'status'         => Job::STATUS_RESERVED,
            'reserved_at'    => Carbon::now()->subMinutes(10),
            'reserved_until' => Carbon::now()->subSecond(),
        ]);

        $service = $this->service();
        $this->assertSame(1, $service->reclaimStaleReservations('print'));

        $fresh = Job::find($job->id);
        $this->assertSame(Job::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->reserved_at);
        $this->assertNull($fresh->reserved_until);
        $this->assertSame(1, $fresh->attempts, 'The attempt consumed at claim time is not refunded');

        // And it is then claimed and completed normally.
        $this->assertTrue($service->processNext('print'));
        $this->assertSame(Job::STATUS_COMPLETED, Job::find($job->id)->status);
    }

    /** AC2 */
    public function testStaleReservationWithNoAttemptsLeftBecomesFailed(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\SucceedJob', 3, 3, [
            'status'         => Job::STATUS_RESERVED,
            'reserved_at'    => Carbon::now()->subMinutes(10),
            'reserved_until' => Carbon::now()->subSecond(),
        ]);

        $service = $this->service();

        // processNext sweeps first, then finds nothing claimable.
        $this->assertFalse($service->processNext('print'));

        $fresh = Job::find($job->id);
        $this->assertSame(Job::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->failed_at);
        $this->assertNull($fresh->reserved_at);
        $this->assertNull($fresh->reserved_until);
        $this->assertStringContainsString('Reserva expirada', (string) $fresh->last_error);
    }

    public function testReservationStillInsideItsDeadlineIsNotReclaimed(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\SucceedJob', 1, 3, [
            'status'         => Job::STATUS_RESERVED,
            'reserved_at'    => Carbon::now(),
            'reserved_until' => Carbon::now()->addMinutes(5),
        ]);

        $service = $this->service();
        $this->assertSame(0, $service->reclaimStaleReservations('print'));
        $this->assertSame(Job::STATUS_RESERVED, Job::find($job->id)->status);
    }

    /** AC6 */
    public function testUnresolvableHandlerFailsInsteadOfBeingDeleted(): void
    {
        $job = $this->makeJob('App\\Jobs\\ThisClassDoesNotExist', 2, 3);

        $this->assertTrue($this->service()->processNext('print'));

        $fresh = Job::find($job->id);
        $this->assertNotNull($fresh, 'An unresolvable handler must not silently delete the job');
        $this->assertSame(Job::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('ThisClassDoesNotExist', (string) $fresh->last_error);
    }

    /** AC7 */
    public function testClaimSetsReservationDeadlineFromSettings(): void
    {
        $this->makeJob(__NAMESPACE__ . '\ProbeJob', 0, 3);

        $settings = new Settings();
        $service  = new JobService(new NullLogger(), $settings);
        $service->processNext('print');

        $this->assertTrue(ProbeJob::$called, 'Handler must be executed');
        $this->assertNotNull(ProbeJob::$reservedAt, 'reserved_at must be set while the job runs');
        $this->assertNotNull(ProbeJob::$reservedUntil, 'reserved_until must be set while the job runs');

        $expected = ProbeJob::$reservedAt->copy()->addSeconds($settings->getQueueReservationTimeout());
        $this->assertLessThanOrEqual(
            1,
            abs(ProbeJob::$reservedUntil->diffInSeconds($expected)),
            'reserved_until must be reserved_at + QUEUE_RESERVATION_TIMEOUT'
        );
    }

    /** @param array<string, mixed> ...$args */
    private function invokePrivate(JobService $service, string $method, ...$args): mixed
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$args);
    }

    /**
     * Spec 037, AC1 + AC2 — the defect spec 035 found.
     *
     * The handler already ran. If the completion UPDATE deadlocks, the job must NOT return to
     * 'pending', because re-queueing it would do the work a second time (duplicate ticket, or
     * a duplicate NFC-e once the fiscal job exists).
     */
    public function testCompletionThatCannotBeRecordedParksTheJobInsteadOfRequeueing(): void
    {
        $job = new DeadlockingJob();
        $job->id = 4242;
        $job->queue = 'print';
        $job->attempts = 1;
        $job->max_attempts = 3;

        $service = $this->service();
        $this->invokePrivate($service, 'recordCompletion', $job);

        $statuses = array_map(static fn (array $u) => $u['status'] ?? null, $job->updates);

        $this->assertNotContains(
            Job::STATUS_PENDING,
            $statuses,
            'A job whose handler already ran must never be put back on the queue'
        );

        $final = end($job->updates);
        $this->assertSame(Job::STATUS_FAILED, $final['status']);
        $this->assertNotNull($final['failed_at']);
        $this->assertStringContainsString('JÁ FOI EXECUTADO', (string) $final['last_error']);
    }

    /** Spec 037 — the completion UPDATE is retried before the job is parked. */
    public function testCompletionIsRetriedBeforeGivingUp(): void
    {
        $job = new DeadlockingJob();
        $job->id = 4243;
        $job->queue = 'print';

        $this->invokePrivate($this->service(), 'recordCompletion', $job);

        $completionAttempts = count(array_filter(
            $job->updates,
            static fn (array $u) => ($u['status'] ?? null) === Job::STATUS_COMPLETED
        ));

        // 1 initial try + MAX_DB_RETRIES (3).
        $this->assertSame(4, $completionAttempts, 'Completion must be retried, bounded');
    }

    /** Spec 037, AC3 — the retry helper is bounded and only retries transient errors. */
    public function testRetryHelperRetriesDeadlocksAndThenGivesUp(): void
    {
        $calls = 0;
        $service = $this->service();

        try {
            $this->invokePrivate($service, 'retryOnTransientDbError', 'test', function () use (&$calls) {
                $calls++;
                throw new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
            }, null);
            $this->fail('The helper must rethrow once its retries are exhausted');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('1213', $e->getMessage());
        }

        $this->assertSame(4, $calls, '1 attempt + 3 retries');
    }

    /** A non-transient error must fail immediately — retrying it would just waste time. */
    public function testRetryHelperDoesNotRetryOrdinaryErrors(): void
    {
        $calls = 0;

        try {
            $this->invokePrivate($this->service(), 'retryOnTransientDbError', 'test', function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('column not found');
            }, null);
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('column not found', $e->getMessage());
        }

        $this->assertSame(1, $calls, 'A non-transient error must not be retried');
    }

    /** AC8 */
    public function testPruneRemovesOldCompletedJobsOnly(): void
    {
        $old = $this->makeJob(__NAMESPACE__ . '\SucceedJob', 0, 3, [
            'status'       => Job::STATUS_COMPLETED,
            'completed_at' => Carbon::now()->subDays(8),
        ]);
        $recent = $this->makeJob(__NAMESPACE__ . '\SucceedJob', 0, 3, [
            'status'       => Job::STATUS_COMPLETED,
            'completed_at' => Carbon::now()->subDays(6),
        ]);
        $failed = $this->makeJob(__NAMESPACE__ . '\FailingJob', 3, 3, [
            'status'    => Job::STATUS_FAILED,
            'failed_at' => Carbon::now()->subDays(30),
        ]);

        $this->assertSame(1, $this->service()->pruneCompleted(7));

        $this->assertNull(Job::find($old->id), 'Completed job older than retention must be pruned');
        $this->assertNotNull(Job::find($recent->id), 'Completed job inside retention must be kept');
        $this->assertNotNull(Job::find($failed->id), 'Failed jobs are never pruned automatically');
    }
}
