<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class JobService
{
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
        // Reserve the next available job (atomic SELECT … FOR UPDATE)
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

        if (!$job) {
            return false;
        }

        // Execute the job
        try {
            $payload = json_decode($job->payload, true);
            $handler = $payload['handler'] ?? null;
            $data    = $payload['data'] ?? [];

            if ($handler && class_exists($handler)) {
                $instance = new $handler();
                $instance->handle($data);
            }

            // Success — delete the job
            $job->delete();
        } catch (\Throwable $e) {
            // If max attempts reached, leave in DB for inspection.
            // Otherwise release it so it can be retried.
            if ($job->attempts >= $job->max_attempts) {
                $job->update(['reserved_at' => null]);
            } else {
                // Release with a small delay (exponential backoff)
                $backoff = pow(2, $job->attempts);
                $job->update([
                    'reserved_at'  => null,
                    'available_at' => Carbon::now()->addSeconds($backoff),
                ]);
            }
        }

        return true;
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
