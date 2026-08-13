<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
        parent::tearDown();
    }

    public function testItReadsDatabaseValuesLoadedIntoEnv(): void
    {
        $_ENV['DB_HOST'] = 'database.internal';
        $_ENV['DB_PORT'] = '3307';
        $_ENV['DB_DATABASE'] = 'sync_test';
        $_ENV['DB_USERNAME'] = 'sync_user';
        $_ENV['DB_PASSWORD'] = 'secret';

        $database = require dirname(__DIR__, 2) . '/config/database.php';

        self::assertSame('database.internal', $database['host']);
        self::assertSame(3307, $database['port']);
        self::assertSame('sync_test', $database['database']);
        self::assertSame('sync_user', $database['username']);
        self::assertSame('secret', $database['password']);
    }
}
