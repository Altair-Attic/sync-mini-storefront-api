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
        self::assertSame('testing', getenv('APP_ENV'), 'Database integration tests require APP_ENV=testing.');
        self::assertSame('1', $_ENV['RUN_DB_INTEGRATION_TESTS'] ?? null, 'Database integration tests require RUN_DB_INTEGRATION_TESTS=1.');
        $database = require $root . '/config/database.php';
        self::assertStringEndsWith('_test', $database['database'], 'Database integration tests require a disposable *_test database.');
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
