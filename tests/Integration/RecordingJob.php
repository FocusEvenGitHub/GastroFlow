<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Job;

/**
 * Handler for the multi-worker load test (spec 077). Appends the job id to the file in
 * $data['log'] on every run, so a handler executed twice shows up as a duplicate line —
 * something `attempts` alone cannot reveal.
 *
 * Own PSR-4 file for the same reason as NoopJob: the worker processes autoload through
 * composer and never load the test file.
 */
class RecordingJob
{
    public function handle(array $data, ?Job $job = null): void
    {
        $handle = fopen((string) $data['log'], 'a');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open execution log: ' . $data['log']);
        }

        // LOCK_EX keeps lines from concurrent workers whole.
        flock($handle, LOCK_EX);
        fwrite($handle, ($job?->id ?? 'null') . "\n");
        flock($handle, LOCK_UN);
        fclose($handle);

        // Stands in for the printer round-trip, so workers overlap for longer.
        usleep(random_int(1_000, 5_000));
    }
}
