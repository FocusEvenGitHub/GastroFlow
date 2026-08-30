<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Job;
use App\Services\JobService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;

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

class JobServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Db::schema()->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue', 50)->default('default');
            $table->text('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    private function makeJob(string $handler, int $attempts, int $maxAttempts): Job
    {
        return Job::create([
            'queue'        => 'print',
            'payload'      => json_encode(['handler' => $handler, 'data' => []]),
            'attempts'     => $attempts,
            'max_attempts' => $maxAttempts,
            'available_at' => Carbon::now()->subMinute(),
        ]);
    }

    public function testFailingJobIsNotDeletedAndIncrementsAttempts(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\FailingJob', 0, 3);

        $service = new JobService();
        $this->assertTrue($service->processNext('print'));

        $fresh = Job::find($job->id);
        $this->assertNotNull($fresh, 'Job must NOT be deleted when the handler throws');
        $this->assertSame(1, $fresh->attempts);
    }

    public function testFailingJobIsReleasedForRetryBeforeMaxAttempts(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\FailingJob', 0, 3);

        $service = new JobService();
        $service->processNext('print');

        $fresh = Job::find($job->id);
        $this->assertNull($fresh->reserved_at, 'Job must be released (not reserved) for retry');
        $this->assertTrue(
            $fresh->available_at->greaterThan(Carbon::now()),
            'Job must have a future available_at for retry backoff'
        );
    }

    public function testMaxAttemptsReachedKeepsJobInDbAndNotReprocessed(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\FailingJob', 2, 3);

        $service = new JobService();
        $this->assertTrue($service->processNext('print'));

        $fresh = Job::find($job->id);
        $this->assertNotNull($fresh, 'Job must remain in the DB for inspection after max attempts');
        $this->assertSame(3, $fresh->attempts);
        $this->assertNull($fresh->reserved_at);

        // It must not be selected/processed again.
        $this->assertFalse($service->processNext('print'));
    }

    public function testSuccessfulJobIsDeleted(): void
    {
        $job = $this->makeJob(__NAMESPACE__ . '\SucceedJob', 0, 3);

        $service = new JobService();
        $this->assertTrue($service->processNext('print'));

        $this->assertNull(Job::find($job->id), 'Job must be deleted on handler success');
    }

    public function testProcessNextReturnsFalseWhenNoJobsAvailable(): void
    {
        $service = new JobService();
        $this->assertFalse($service->processNext('print'));
    }
}
