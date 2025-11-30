<?php

$dbHost = getenv('DB_HOST') ?: 'db';
$dbName = getenv('MYSQL_DATABASE') ?: 'restaurant';
$dbUser = getenv('MYSQL_USER') ?: 'restuser';
$dbPass = getenv('MYSQL_PASSWORD') ?: 'restpass';

$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
