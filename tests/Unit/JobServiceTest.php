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
