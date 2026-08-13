<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}
$database = require $root . '/config/database.php';
$config = new Config([
    'db.host' => $database['host'],
    'db.port' => (string) $database['port'],
    'db.database' => $database['database'],
    'db.username' => $database['username'],
    'db.password' => $database['password'],
]);
$executed = (new MigrationRunner((new DatabaseConnection($config))->connect(), $root . '/database/migrations'))->run();
echo sprintf("Migrations complete. Executed: %s%s", $executed === [] ? 'none' : implode(', ', $executed), PHP_EOL);
