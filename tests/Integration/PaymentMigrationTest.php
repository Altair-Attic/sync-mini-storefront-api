<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;

final class PaymentMigrationTest extends TestCase
{
    private PDO $db;
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($root);
        $database = require $root . '/config/database.php';
        self::assertStringEndsWith('_test', $database['database']);
        $this->db = (new DatabaseConnection(new Config([
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
        ])))->connect();
        $this->runner = new MigrationRunner($this->db, $root . '/database/migrations');
    }

    public function testMigrationsAreIdempotent(): void
    {
        // First run applies any pending
        $this->runner->run();

        // Second run must apply 0 migrations
        $secondRun = $this->runner->run();
        self::assertSame([], $secondRun);
    }

    public function testForeignKeysEnforceOnDeleteRestrict(): void
    {
        $this->runner->run();

        $orderId = UuidGenerator::v4();
        $ref = 'TEST-ORD-' . bin2hex(random_bytes(4));
        $stmtOrder = $this->db->prepare(
            'INSERT INTO orders (id, reference, customer_name, phone_number, customer_email, fulfilment_method, '
            . 'subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, '
            . 'fulfilment_status, confirmation_token_hash, idempotency_key_hash, created_at, updated_at) '
            . "VALUES (:id, :ref, 'Test Customer', '+2348000000000', 'cust@example.com', 'pickup', "
            . "10000, 0, 10000, 'NGN', 'paystack', 'unpaid', 'new', :token_hash, :idem_hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmtOrder->execute([
            'id' => $orderId,
            'ref' => $ref,
            'token_hash' => hash('sha256', 'token-seed-' . $orderId),
            'idem_hash' => hash('sha256', 'migration-order-idem-' . $orderId),
        ]);




        $attemptId = UuidGenerator::v4();
        $payRef = 'PAY-SYNC-' . bin2hex(random_bytes(10));
        $stmtAttempt = $this->db->prepare(
            'INSERT INTO payment_attempts (id, order_id, provider, internal_reference, idempotency_key_hash, '
            . 'expected_amount_kobo, currency, status, resolution_status, initiated_at, created_at, updated_at) '
            . "VALUES (:id, :order_id, 'paystack', :pay_ref, 'hash123', 10000, 'NGN', 'pending', 'none', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmtAttempt->execute(['id' => $attemptId, 'order_id' => $orderId, 'pay_ref' => $payRef]);

        // Attempting to delete the order while a payment attempt exists MUST fail with foreign key violation (ON DELETE RESTRICT)
        $this->expectException(PDOException::class);
        $this->expectExceptionCode('23000');

        $this->db->exec("DELETE FROM orders WHERE id = '{$orderId}'");
    }
}
