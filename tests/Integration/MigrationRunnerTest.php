<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\MigrationRunner;

final class MigrationRunnerTest extends TestCase
{
    public function testMysqlMigrationsDoNotUsePdoTransactionsForDdl(): void
    {
        $connection = $this->createMock(PDO::class);
        $applied = $this->createMock(PDOStatement::class);
        $batch = $this->createMock(PDOStatement::class);
        $record = $this->createMock(PDOStatement::class);
        $connection->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $connection->method('exec')->willReturn(0);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::exactly(3))->method('prepare')->willReturnOnConsecutiveCalls($applied, $batch, $record);
        $applied->method('execute')->willReturn(true);
        $applied->method('fetchAll')->willReturn([]);
        $batch->method('execute')->willReturn(true);
        $batch->method('fetchColumn')->willReturn(1);
        $record->method('execute')->willReturn(true);

        $executed = (new MigrationRunner($connection, dirname(__DIR__, 2) . '/database/migrations'))->run();

        self::assertSame(['202608130001_create_schema_migrations'], $executed);
    }
}
