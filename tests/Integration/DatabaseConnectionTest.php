<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;

final class DatabaseConnectionTest extends TestCase
{
    public function testConfiguredMysqlDatabaseAcceptsConnections(): void
    {
        if (getenv('RUN_DB_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_INTEGRATION_TESTS=1 with disposable MySQL credentials to run this test.');
        }
        $connection = new DatabaseConnection(new Config([
            'db.host' => (string) getenv('DB_HOST'),
            'db.port' => (string) getenv('DB_PORT'),
            'db.database' => (string) getenv('DB_DATABASE'),
            'db.username' => (string) getenv('DB_USERNAME'),
            'db.password' => (string) getenv('DB_PASSWORD'),
        ]));

        self::assertInstanceOf(PDO::class, $connection->connect());
    }
}
