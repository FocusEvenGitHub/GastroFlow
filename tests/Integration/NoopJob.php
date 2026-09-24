<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Job;

/**
 * Handler for the concurrency fixture (spec 035, AC8). Does nothing, so what the test
 * measures is the claim itself and not the work.
 *
 * It lives in its own PSR-4 file on purpose. Declared inside ConcurrencyTest.php it was
 * invisible to the separate worker processes, which autoload through composer and never
 * load the test file — the handler then failed to resolve, the job took the retry path,
 * and the test became flaky (it passed 2 runs in 3).
 */
class NoopJob
{
    public function handle(array $data, ?Job $job = null): void
    {
    }
}
