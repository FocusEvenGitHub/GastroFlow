<?php

declare(strict_types=1);

/**
 * Claims one job from a queue and reports the outcome, for the concurrency test in
 * spec 035 (AC8).
 *
 * This runs as its own OS process on purpose. Two JobService::processNext() calls made
 * inside one PHP process would share a PDO connection and therefore a transaction, so
 * `SELECT ... FOR UPDATE` would never contend and the test would pass while proving
 * nothing. Separate processes give separate connections, which is the only way the lock
 * is actually exercised.
 *
 * Usage: php claim-one-job.php <queue> <test-database>
 * Prints "claimed" or "none", or "error: ..." on failure.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

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

    $claimed = (new App\Services\JobService(new Psr\Log\NullLogger()))->processNext($queue);

    echo $claimed ? "claimed\n" : "none\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
