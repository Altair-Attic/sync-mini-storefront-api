<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;

final class DatabaseConnectionTest extends TestCase
{
    public function testConfiguredMysqlDatabaseAcceptsConnections(): void
    {
        $root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($root);
        if (($_ENV['RUN_DB_INTEGRATION_TESTS'] ?? null) !== '1') {
            self::markTestSkipped('Set RUN_DB_INTEGRATION_TESTS=1 with disposable MySQL credentials to run this test.');
        }
        $database = require $root . '/config/database.php';
        $connection = new DatabaseConnection(new Config([
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
        ]));

        self::assertInstanceOf(PDO::class, $connection->connect());
    }
}
