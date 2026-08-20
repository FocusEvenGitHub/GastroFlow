<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

// In CI, configuration arrives as real environment variables rather than a
// .env file; PHP's default variables_order doesn't populate $_ENV from those,
// while App\Settings::get() only reads $_ENV — so bridge anything missing.
foreach (getenv() as $key => $value) {
    if (!array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}
