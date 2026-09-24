<?php

declare(strict_types=1);

/**
 * Creates one order with a server-assigned order_number, for the concurrency test in
 * spec 035 (AC9). Runs as its own OS process for the same reason as claim-one-job.php:
 * separate connections are what make `allocateNextNumber()`'s lock meaningful.
 *
 * Usage: php create-one-order.php <menu-item-id> <test-database>
 * Prints the assigned order_number, or "error: ..." on failure.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

// Same getenv() -> $_ENV bridge as tests/bootstrap.php: in CI the configuration arrives as
// real environment variables, immutable Dotenv leaves those alone, and Settings reads $_ENV.
foreach (getenv() as $key => $value) {
    if (!array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$menuItemId = (int) ($argv[1] ?? 0);
$database   = $argv[2] ?? null;

if ($menuItemId <= 0 || $database === null || $database === '') {
    fwrite(STDERR, "error: usage: create-one-order.php <menu-item-id> <test-database>\n");
    exit(2);
}

$_ENV['MYSQL_DATABASE'] = $database;

try {
    App\Database::boot(new App\Settings());

    $repository = new App\Repositories\OrderRepository(new App\Services\PricingService());
    $order = $repository->createOrder([
        'items' => [
            ['id' => $menuItemId, 'quantity' => 1],
        ],
    ]);

    echo $order->order_number . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
