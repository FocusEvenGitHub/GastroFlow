<?php

declare(strict_types=1);

/**
 * Drains a queue until processNext() finds nothing, for the multi-worker load test in
 * spec 077. Runs as its own OS process for the reason given in claim-one-job.php: only
 * separate processes get separate connections, and so real lock contention.
 *
 * Usage: php drain-queue.php <queue> <test-database>
 * Prints one JSON line, or "error: ..." on STDERR on failure:
 *   {"processed": n, "retries": {"1213": a, "40001": b, "1205": c, "other": d},
 *    "operations": {"claim": x, ...}, "seconds": t}
 * where operations counts the retries by JobService operation.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

// Same getenv() → $_ENV bridge as claim-one-job.php (CI passes config as real env vars).
foreach (getenv() as $key => $value) {
    if (!array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$queue    = $argv[1] ?? 'default';
$database = $argv[2] ?? null;

if ($database === null || $database === '') {
    fwrite(STDERR, "error: missing test database argument\n");
    exit(2);
}

// Never let this script run against anything but the database it was handed.
$_ENV['MYSQL_DATABASE'] = $database;

try {
    App\Database::boot(new App\Settings());

    // JobService logs each transient-error retry as a warning; keep them in memory to count.
    $records = new Monolog\Handler\TestHandler();
    $logger  = new Monolog\Logger('drain', [$records]);
    $service = new App\Services\JobService($logger);

    $start     = microtime(true);
    $processed = 0;
    while ($service->processNext($queue)) {
        $processed++;
    }
    $seconds = round(microtime(true) - $start, 3);

    $retries    = ['1213' => 0, '40001' => 0, '1205' => 0, 'other' => 0];
    $operations = [];
    foreach ($records->getRecords() as $record) {
        if ($record->level !== Monolog\Level::Warning) {
            continue;
        }
        $error = (string) ($record->context['error'] ?? '');
        $code  = 'other';
        foreach (['1213', '40001', '1205'] as $marker) {
            if (str_contains($error, $marker)) {
                $code = $marker;
                break;
            }
        }
        $retries[$code]++;
        $operation              = (string) ($record->context['operation'] ?? '?');
        $operations[$operation] = ($operations[$operation] ?? 0) + 1;
    }

    echo json_encode(['processed' => $processed, 'retries' => $retries, 'operations' => $operations, 'seconds' => $seconds]) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
