<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\CheckoutException;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\ProductRepository;
use ProjectSync\Services\CheckoutService;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\OrderReferenceGenerator;

final class CheckoutServiceTest extends TestCase
{
    private PDO $db;
    private CheckoutService $checkout;
    private string $productId;
    private string $productPublicId;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($root);
        self::assertSame('1', $_ENV['RUN_DB_INTEGRATION_TESTS'] ?? null);
        $database = require $root . '/config/database.php';
        self::assertStringEndsWith('_test', $database['database']);
        $this->db = (new DatabaseConnection(new Config([
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
        ])))->connect();
        (new MigrationRunner($this->db, $root . '/database/migrations'))->run();
        $this->db->exec('UPDATE business_profiles SET delivery_enabled = TRUE, pickup_enabled = TRUE, fixed_delivery_fee_kobo = 150000, currency = \'NGN\'');
        $this->productId = UuidGenerator::v4();
        $this->productPublicId = UuidGenerator::v4();
        $statement = $this->db->prepare('INSERT INTO products (id, public_id, slug, title, price_kobo, is_active, created_at, updated_at) VALUES (:id, :public_id, :slug, :title, 250000, TRUE, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $statement->execute(['id' => $this->productId, 'public_id' => $this->productPublicId, 'slug' => 'checkout-test-' . substr($this->productId, 0, 8), 'title' => 'Checkout Snapshot Product']);
        $orders = new OrderRepository($this->db);
        $items = new OrderItemRepository($this->db);
        $this->checkout = new CheckoutService(
            $this->db,
            new BusinessProfileRepository($this->db),
            new ProductRepository($this->db),
            $orders,
            $items,
            new OrderReferenceGenerator(),
            new OrderConfirmationTokenService(str_repeat('integration-secret-', 3)),
            4_294_967_295,
        );
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM order_items WHERE product_id = :id')->execute(['id' => $this->productId]);
        $this->db->prepare("DELETE FROM orders WHERE customer_name LIKE 'Checkout Test%'")->execute();
        $this->db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
    }

    public function testDeliveryPricingSnapshotsTransactionIdempotencyAndConfirmation(): void
    {
        $request = $this->request('delivery');
        $initial = $this->checkout->create($request, 'checkout-test-key-000001');
        $replay = $this->checkout->create($request, 'checkout-test-key-000001');

        self::assertFalse($initial->replay);
        self::assertTrue($replay->replay);
        self::assertSame($initial->order['reference'], $replay->order['reference']);
        self::assertSame($initial->confirmationToken, $replay->confirmationToken);
        self::assertSame(500000, $initial->order['subtotal_kobo']);
        self::assertSame(150000, $initial->order['delivery_fee_kobo']);
        self::assertSame(650000, $initial->order['total_kobo']);
        self::assertSame('NGN', $initial->order['currency']);
        $initialItem = $this->firstItem($initial->order);
        self::assertSame('Checkout Snapshot Product', $initialItem['product_title']);
        self::assertSame(250000, $initialItem['unit_price_kobo']);
        self::assertSame(1, $this->countRows('orders'));
        self::assertSame(1, $this->countRows('order_items'));

        $reference = $this->reference($initial->order);
        $confirmation = $this->checkout->confirmation($reference, $initial->confirmationToken);
        self::assertSame($initial->order, $confirmation);
        self::assertArrayNotHasKey('id', $confirmation);
        self::assertArrayNotHasKey('idempotency_key_hash', $confirmation);
        $statement = $this->db->query('SELECT idempotency_key_hash FROM orders LIMIT 1');
        if ($statement === false) {
            self::fail('Could not inspect stored idempotency hash.');
        }
        $rawStored = $statement->fetchColumn();
        self::assertNotSame('checkout-test-key-000001', $rawStored);

        $this->db->prepare('UPDATE products SET title = \'Changed Product\', price_kobo = 1, is_active = FALSE WHERE id = :id')->execute(['id' => $this->productId]);
        $historic = $this->checkout->confirmation($reference, $initial->confirmationToken);
        $historicItem = $this->firstItem($historic);
        self::assertSame('Checkout Snapshot Product', $historicItem['product_title']);
        self::assertSame(250000, $historicItem['unit_price_kobo']);
    }

    public function testPickupHasZeroFeeAndUnavailableProductsAreRejected(): void
    {
        $pickup = $this->checkout->create($this->request('pickup'), 'checkout-test-key-000002');
        self::assertSame(0, $pickup->order['delivery_fee_kobo']);
        self::assertSame(500000, $pickup->order['total_kobo']);

        $this->db->prepare('UPDATE products SET is_active = FALSE WHERE id = :id')->execute(['id' => $this->productId]);
        try {
            $this->checkout->create($this->request('pickup'), 'checkout-test-key-000003');
            self::fail('Expected inactive product rejection.');
        } catch (CheckoutException $exception) {
            self::assertSame('PRODUCT_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function testChangedRequestConflictsAndWrongConfirmationTokenLooksMissing(): void
    {
        $created = $this->checkout->create($this->request('delivery'), 'checkout-test-key-000004');
        $changed = $this->request('delivery');
        $changed['items'] = [['product_id' => $this->productPublicId, 'quantity' => 1]];
        try {
            $this->checkout->create($changed, 'checkout-test-key-000004');
            self::fail('Expected idempotency conflict.');
        } catch (CheckoutException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
        try {
            $this->checkout->confirmation($this->reference($created->order), 'wrong-token');
            self::fail('Expected confirmation failure.');
        } catch (CheckoutException $exception) {
            self::assertSame('ORDER_NOT_FOUND', $exception->errorCode);
            self::assertSame(404, $exception->status);
        }
    }

    public function testDisabledMethodAndTotalLimitAreRejectedBeforePersistence(): void
    {
        $this->db->exec('UPDATE business_profiles SET delivery_enabled = FALSE');
        try {
            $this->checkout->create($this->request('delivery'), 'checkout-test-key-000005');
            self::fail('Expected disabled delivery rejection.');
        } catch (CheckoutException $exception) {
            self::assertSame('FULFILMENT_METHOD_UNAVAILABLE', $exception->errorCode);
        }

        $limited = new CheckoutService(
            $this->db,
            new BusinessProfileRepository($this->db),
            new ProductRepository($this->db),
            new OrderRepository($this->db),
            new OrderItemRepository($this->db),
            new OrderReferenceGenerator(),
            new OrderConfirmationTokenService(str_repeat('integration-secret-', 3)),
            100,
        );
        try {
            $limited->create($this->request('pickup'), 'checkout-test-key-000006');
            self::fail('Expected total limit rejection.');
        } catch (CheckoutException $exception) {
            self::assertSame('ORDER_TOTAL_LIMIT_EXCEEDED', $exception->errorCode);
            self::assertSame(0, $this->countRows('orders'));
        }
    }

    public function testOrderIsRolledBackWhenItemInsertionFails(): void
    {
        $this->db->exec('DROP TRIGGER IF EXISTS checkout_test_reject_item');
        $this->db->exec(
            "CREATE TRIGGER checkout_test_reject_item BEFORE INSERT ON order_items FOR EACH ROW "
            . "BEGIN IF NEW.product_id = '" . $this->productId . "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected item failure'; END IF; END"
        );
        try {
            $this->checkout->create($this->request('pickup'), 'checkout-test-key-rollback');
            self::fail('Expected injected order item failure.');
        } catch (\PDOException) {
            self::assertSame(0, $this->countRows('orders'));
            self::assertSame(0, $this->countRows('order_items'));
        } finally {
            $this->db->exec('DROP TRIGGER IF EXISTS checkout_test_reject_item');
        }
    }

    /** @return array{customer_name: string, phone_number: string, customer_email: string|null, fulfilment_method: string, delivery_address: string|null, state: string|null, payment_method: string, items: list<array{product_id: string, quantity: int}>} */
    private function request(string $method): array
    {
        return [
            'customer_name' => 'Checkout Test Customer',
            'phone_number' => '+2349035732952',
            'customer_email' => 'checkout@example.com',
            'fulfilment_method' => $method,
            'delivery_address' => $method === 'delivery' ? '12 Example Street' : null,
            'state' => $method === 'delivery' ? 'Ogun' : null,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $this->productPublicId, 'quantity' => 2]],
        ];
    }

    private function countRows(string $table): int
    {
        $statement = $this->db->query('SELECT COUNT(*) FROM ' . $table . " WHERE " . ($table === 'orders' ? "customer_name LIKE 'Checkout Test%'" : 'product_id = ' . $this->db->quote($this->productId)));
        if ($statement === false) {
            self::fail('Could not count checkout test records.');
        }

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function firstItem(array $order): array
    {
        $items = $order['items'] ?? null;
        if (!is_array($items) || !isset($items[0]) || !is_array($items[0])) {
            self::fail('Order must contain an item.');
        }

        $result = [];
        foreach ($items[0] as $field => $value) {
            if (!is_string($field)) {
                self::fail('Order item must have string field names.');
            }
            $result[$field] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $order */
    private function reference(array $order): string
    {
        $reference = $order['reference'] ?? null;
        if (!is_string($reference)) {
            self::fail('Order reference must be a string.');
        }

        return $reference;
    }
}
