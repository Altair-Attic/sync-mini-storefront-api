<?php

declare(strict_types=1);

use ProjectSync\Exceptions\ConfigurationException;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $root = dirname(__DIR__);
    ApplicationBootstrap::loadEnvironment($root);
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
} catch (ConfigurationException $exception) {
    fwrite(STDERR, sprintf("Migration configuration error: %s%s", $exception->getMessage(), PHP_EOL));
    exit(1);
} catch (\PDOException) {
    fwrite(STDERR, sprintf("Migration failed: the database connection could not be established.%s", PHP_EOL));
    exit(1);
} catch (\Throwable) {
    fwrite(STDERR, sprintf("Migration failed. Review the application log for details.%s", PHP_EOL));
    exit(1);
}
