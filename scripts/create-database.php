<?php

use Illuminate\Contracts\Console\Kernel;

// Local setup only. Uses .env credentials without printing them.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.mysql');
$name = $config['database'];
if (! preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
    throw new RuntimeException('Use an alphanumeric database name.');
}
$connection = new PDO(
    "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$connection->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Application database is ready.\n";
