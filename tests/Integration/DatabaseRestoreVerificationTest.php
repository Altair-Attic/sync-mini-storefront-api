<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;

final class DatabaseRestoreVerificationTest extends TestCase
{
    private string $root;
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($this->root);
        self::assertSame('1', $_ENV['RUN_DB_INTEGRATION_TESTS'] ?? null);
        $database = require $this->root . '/config/database.php';
        self::assertStringEndsWith('_test', (string) $database['database']);
        $this->db = (new DatabaseConnection(new Config([
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
        ])))->connect();
    }

    public function testDatabaseBackupAndRestoreVerification(): void
    {
        $runner = new MigrationRunner($this->db, $this->root . '/database/migrations');
        $runner->run();
        $this->assertSame([], $runner->pending());

        // 1. Create a dedicated isolated test backup table
        $this->db->exec('DROP TABLE IF EXISTS _backup_test_restore');
        $this->db->exec('CREATE TABLE _backup_test_restore (id INT AUTO_INCREMENT PRIMARY KEY, key_name VARCHAR(100) NOT NULL, val VARCHAR(255) NOT NULL)');
        $this->db->exec("INSERT INTO _backup_test_restore (key_name, val) VALUES ('site_status', 'active'), ('version', '1.0.0')");

        // 2. Export / dump simulation
        $stmt = $this->db->query('SELECT key_name, val FROM _backup_test_restore ORDER BY id ASC');
        $this->assertNotFalse($stmt);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dumpStatements = [];
        $dumpStatements[] = 'DROP TABLE IF EXISTS _backup_test_restore_target';
        $dumpStatements[] = 'CREATE TABLE _backup_test_restore_target (id INT AUTO_INCREMENT PRIMARY KEY, key_name VARCHAR(100) NOT NULL, val VARCHAR(255) NOT NULL)';
        foreach ($rows as $row) {
            $keyName = is_scalar($row['key_name'] ?? null) ? (string) $row['key_name'] : '';
            $val = is_scalar($row['val'] ?? null) ? (string) $row['val'] : '';
            $dumpStatements[] = sprintf(
                "INSERT INTO _backup_test_restore_target (key_name, val) VALUES (%s, %s)",
                $this->db->quote($keyName),
                $this->db->quote($val)
            );
        }

        // 3. Restore dump statements
        foreach ($dumpStatements as $sql) {
            $this->db->exec($sql);
        }

        // 4. Verify restored table has exact records
        $verifyStmt = $this->db->query('SELECT key_name, val FROM _backup_test_restore_target ORDER BY id ASC');
        $this->assertNotFalse($verifyStmt);
        $restoredRows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame($rows, $restoredRows);

        // 5. Cleanup
        $this->db->exec('DROP TABLE IF EXISTS _backup_test_restore');
        $this->db->exec('DROP TABLE IF EXISTS _backup_test_restore_target');

        // 6. Verify migration status remains consistent
        $this->assertSame([], $runner->pending());
    }
}
