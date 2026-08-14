<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;

final class NotificationMigrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($root);
        $database = require $root . '/config/database.php';
        self::assertStringEndsWith('_test', $database['database']);
        $this->db = (new DatabaseConnection(new Config([
            'db.host' => $database['host'], 'db.port' => (string) $database['port'],
            'db.database' => $database['database'], 'db.username' => $database['username'], 'db.password' => $database['password'],
        ])))->connect();
        (new MigrationRunner($this->db, $root . '/database/migrations'))->run();
    }

    public function testNotificationMigrationsAreTrackedAndRepeatRunIsEmpty(): void
    {
        $runner = new MigrationRunner($this->db, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $runner->run());
        $statement = $this->db->query("SELECT migration FROM schema_migrations WHERE migration LIKE '20260813001%' ORDER BY migration");
        self::assertNotFalse($statement);
        self::assertSame([
            '202608130010_add_notification_settings_to_business_profiles',
            '202608130011_create_notification_jobs_table',
        ], $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testNotificationDefaultsAndIndexesArePresent(): void
    {
        $columns = $this->db->query("SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_profiles' AND COLUMN_NAME IN ('merchant_email_notifications_enabled','customer_email_notifications_enabled','whatsapp_handoff_enabled')");
        self::assertNotFalse($columns);
        $defaults = [];
        foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['COLUMN_NAME'] ?? null)) {
                $default = $row['COLUMN_DEFAULT'] ?? null;
                if (!is_string($default) && !is_int($default)) {
                    self::fail('Expected a scalar database default.');
                }
                $defaults[$row['COLUMN_NAME']] = (string) $default;
            }
        }
        self::assertSame('1', $defaults['merchant_email_notifications_enabled']);
        self::assertSame('0', $defaults['customer_email_notifications_enabled']);
        self::assertSame('1', $defaults['whatsapp_handoff_enabled']);

        $indexes = $this->db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_jobs'");
        self::assertNotFalse($indexes);
        $names = $indexes->fetchAll(PDO::FETCH_COLUMN);
        foreach (['uq_notification_jobs_recipient', 'idx_notification_jobs_due', 'idx_notification_jobs_order', 'idx_notification_jobs_status_attempts'] as $name) {
            self::assertContains($name, $names);
        }
    }
}
