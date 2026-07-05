<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Settings;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$settings = new Settings();
$app = (new App($settings))->get();

$app->run();