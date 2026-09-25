<?php

declare(strict_types=1);

/**
 * Publishes one event, for the cross-container delivery test in spec 041 (AC3).
 *
 * Runs as its own OS process on purpose — this is the defect the whole spec exists to fix.
 * The old mechanism (sys_get_temp_dir()) is per-container: the print worker and the web
 * container never shared a filesystem, so an event from one was invisible to the other.
 * Simulating that with two objects in one PHP process sharing one DB connection would prove
 * nothing about the container boundary; two separate OS processes, each with its own
 * connection, is what actually exercises it — same technique as
 * tests/Integration/bin/claim-one-job.php (spec 037).
 *
 * Usage: php publish-one-event.php <type> <order-id> <test-database>
 * Prints the inserted event id, or "error: ..." on failure.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

// Same getenv() -> $_ENV bridge as claim-one-job.php (spec 037) and the spec 040 e2e helpers:
// in CI configuration arrives as real environment variables, immutable Dotenv leaves those
// alone, and Settings reads $_ENV only.
foreach (getenv() as $key => $value) {
    if (!array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$type     = $argv[1] ?? null;
$orderId  = isset($argv[2]) ? (int) $argv[2] : null;
$database = $argv[3] ?? null;

if ($type === null || $database === null || $database === '') {
    fwrite(STDERR, "error: usage: publish-one-event.php <type> <order-id> <test-database>\n");
    exit(2);
}

// The database to use — set here, not read from .env, so this script always operates on
// exactly what its caller (the integration test) handed it.
$_ENV['MYSQL_DATABASE'] = $database;

try {
    App\Database::boot(new App\Settings());

    $publisher = new App\Services\DatabaseEventPublisher();
    $publisher->publish($type, $orderId !== null ? ['order_id' => $orderId] : []);

    $id = (int) App\Models\Event::max('id');
    echo $id . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
