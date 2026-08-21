<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\Email\FakeEmailSender;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Repositories\NotificationJobRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\OrderStatusHistoryRepository;
use ProjectSync\Repositories\RevokedAccessTokenRepository;
use ProjectSync\Services\NotificationProcessor;
use ProjectSync\Services\NotificationService;
use ProjectSync\Services\OrderEmailBuilder;
use ProjectSync\Services\OrderManagementService;
use ProjectSync\Services\WhatsAppHandoffService;
use ProjectSync\Validators\OrderListQueryValidator;
use ProjectSync\Validators\OrderStatusUpdateValidator;
use ProjectSync\Controllers\Admin\OrderManagementController;
use Psr\Log\NullLogger;

final class AdminOrderApiTest extends TestCase
{
    private const string ADMIN_ID = '22222222-2222-4222-8222-222222222222';
    private PDO $db;
    private JwtService $jwt;
    private string $productId;
    private string $productPublicId;
    private OrderManagementController $controller;
    private string $currentBody = '';

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
        $this->db->exec('ALTER TABLE notification_jobs MODIFY recipient_type VARCHAR(32) NOT NULL');

        // Seed admin user
        $this->db->prepare('DELETE FROM merchant_users WHERE id = :id OR email = \'admin@example.com\'')->execute(['id' => self::ADMIN_ID]);
        $this->db->prepare('INSERT INTO merchant_users (id, name, email, password_hash, status, created_at, updated_at) VALUES (:id, \'Admin Owner\', \'admin@example.com\', \'$2y$10$dummy\', \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['id' => self::ADMIN_ID]);

        // Seed business profile
        $this->db->exec(
            "UPDATE business_profiles SET business_name = 'Admin Order Test Store', whatsapp_number = '+2348035732952', "
            . "support_email = 'support@example.com', order_notification_email = 'orders@example.com', "
            . "merchant_email_notifications_enabled = TRUE, customer_email_notifications_enabled = TRUE, "
            . "whatsapp_handoff_enabled = TRUE, delivery_enabled = TRUE, pickup_enabled = TRUE, fixed_delivery_fee_kobo = 5000"
        );

        // Seed product
        $this->productId = UuidGenerator::v4();
        $this->productPublicId = UuidGenerator::v4();
        $this->db->prepare("INSERT INTO products (id, public_id, slug, title, price_kobo, is_active, created_at, updated_at) VALUES (:id, :public_id, :slug, 'Order Test Item', 5000, TRUE, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([
            'id' => $this->productId, 'public_id' => $this->productPublicId, 'slug' => 'order-test-item-' . substr($this->productId, 0, 8),
        ]);

        $this->jwt = new JwtService('admin-order-test-jwt-secret-32-bytes', 28800, 'HS256');
        $authMiddleware = new AuthenticationMiddleware(
            $this->jwt,
            new MerchantUserRepository($this->db),
        );

        $notificationService = new NotificationService(
            new BusinessProfileRepository($this->db),
            new NotificationJobRepository($this->db),
            new NotificationProcessor(
                new NotificationJobRepository($this->db),
                new OrderRepository($this->db),
                new OrderItemRepository($this->db),
                new BusinessProfileRepository($this->db),
                new FakeEmailSender(),
                new OrderEmailBuilder(),
                new NullLogger(),
                300,
                900,
            ),
            new WhatsAppHandoffService(),
            new NullLogger(),
            5,
            false,
        );

        $orderManagementService = new OrderManagementService(
            $this->db,
            new OrderRepository($this->db),
            new OrderItemRepository($this->db),
            new OrderStatusHistoryRepository($this->db),
            new OrderListQueryValidator(),
            new OrderStatusUpdateValidator(),
            $notificationService,
        );

        $this->controller = new OrderManagementController(
            $orderManagementService,
            $authMiddleware,
            fn (): string => $this->currentBody,
        );
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM payment_events');
        $this->db->exec('DELETE FROM payment_attempts');
        $this->db->exec('DELETE FROM order_status_history');
        $this->db->exec('DELETE FROM notification_jobs');
        $this->db->exec('DELETE FROM order_items');
        $this->db->exec('DELETE FROM orders');
        $this->db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
        $this->db->prepare('DELETE FROM merchant_users WHERE id = :id OR email = \'admin@example.com\'')->execute(['id' => self::ADMIN_ID]);
    }


    public function testAdminOrderRoutesRequireAuthentication(): void
    {
        $orderId = $this->createTestOrder('Alice', 'new', 10000);

        $listRes = $this->dispatch('GET', '/api/v1/admin/orders', null, false);
        self::assertSame(401, $listRes->status);

        $summaryRes = $this->dispatch('GET', '/api/v1/admin/orders/summary', null, false);
        self::assertSame(401, $summaryRes->status);

        $detailRes = $this->dispatch('GET', "/api/v1/admin/orders/{$orderId}", null, false);
        self::assertSame(401, $detailRes->status);

        $statusRes = $this->dispatch('PATCH', "/api/v1/admin/orders/{$orderId}/status", ['status' => 'confirmed'], false);
        self::assertSame(401, $statusRes->status);
    }

    public function testOrderListingWithPaginationFilteringSearchAndSorting(): void
    {
        $order1 = $this->createTestOrder('Alice Green', 'new', 10000, '2026-08-01 10:00:00', 'REF-001', 'alice@example.com', '+2348030000001');
        $order2 = $this->createTestOrder('Bob White', 'confirmed', 25000, '2026-08-05 12:00:00', 'REF-002', 'bob@example.com', '+2348030000002');
        $order3 = $this->createTestOrder('Charlie Black', 'processing', 15000, '2026-08-10 14:00:00', 'REF-003', 'charlie@example.com', '+2348030000003');

        // All orders list
        $response = $this->dispatch('GET', '/api/v1/admin/orders', null, true);
        self::assertSame(200, $response->status);
        $body = $this->object($response->body);
        $data = $this->items($body['data'] ?? null);
        self::assertCount(3, $data);
        $meta = $this->object($body['meta'] ?? null);
        self::assertSame(1, $meta['page']);
        self::assertSame(3, $meta['total']);

        // Filter by status
        $statusResponse = $this->dispatch('GET', '/api/v1/admin/orders?status=confirmed', null, true);
        self::assertSame(200, $statusResponse->status);
        $statusBody = $this->object($statusResponse->body);
        $statusData = $this->items($statusBody['data'] ?? null);
        self::assertCount(1, $statusData);
        self::assertSame('Bob White', $statusData[0]['customer_name']);

        // Invalid status filter returns 422
        $invalidStatus = $this->dispatch('GET', '/api/v1/admin/orders?status=invalid_status', null, true);
        self::assertSame(422, $invalidStatus->status);
        $invalidBody = $this->object($invalidStatus->body);
        $invalidError = $this->object($invalidBody['error'] ?? null);
        self::assertSame('VALIDATION_FAILED', $invalidError['code']);

        // Search by reference
        $searchRef = $this->dispatch('GET', '/api/v1/admin/orders?search=REF-003', null, true);
        self::assertSame(200, $searchRef->status);
        $searchRefData = $this->items($this->object($searchRef->body)['data'] ?? null);
        self::assertCount(1, $searchRefData);
        self::assertSame('Charlie Black', $searchRefData[0]['customer_name']);

        // Search by customer email
        $searchEmail = $this->dispatch('GET', '/api/v1/admin/orders?search=alice@example.com', null, true);
        self::assertSame(200, $searchEmail->status);
        $searchEmailData = $this->items($this->object($searchEmail->body)['data'] ?? null);
        self::assertCount(1, $searchEmailData);
        self::assertSame('Alice Green', $searchEmailData[0]['customer_name']);

        // Search by phone
        $searchPhone = $this->dispatch('GET', '/api/v1/admin/orders?search=2348030000002', null, true);
        self::assertSame(200, $searchPhone->status);
        $searchPhoneData = $this->items($this->object($searchPhone->body)['data'] ?? null);
        self::assertCount(1, $searchPhoneData);
        self::assertSame('Bob White', $searchPhoneData[0]['customer_name']);

        // Sort by total_high
        $sortHigh = $this->dispatch('GET', '/api/v1/admin/orders?sort=total_high', null, true);
        self::assertSame(200, $sortHigh->status);
        $sortHighData = $this->items($this->object($sortHigh->body)['data'] ?? null);
        self::assertSame('Bob White', $sortHighData[0]['customer_name']);
        self::assertSame('Alice Green', $sortHighData[2]['customer_name']);

        // Sort by total_low
        $sortLow = $this->dispatch('GET', '/api/v1/admin/orders?sort=total_low', null, true);
        self::assertSame(200, $sortLow->status);
        $sortLowData = $this->items($this->object($sortLow->body)['data'] ?? null);
        self::assertSame('Alice Green', $sortLowData[0]['customer_name']);
        self::assertSame('Bob White', $sortLowData[2]['customer_name']);

        // Date range filter
        $dateFilter = $this->dispatch('GET', '/api/v1/admin/orders?date_from=2026-08-04&date_to=2026-08-08', null, true);
        self::assertSame(200, $dateFilter->status);
        $dateFilterData = $this->items($this->object($dateFilter->body)['data'] ?? null);
        self::assertCount(1, $dateFilterData);
        self::assertSame('Bob White', $dateFilterData[0]['customer_name']);
    }

    public function testOrderSummaryCounts(): void
    {
        $this->createTestOrder('User 1', 'new');
        $this->createTestOrder('User 2', 'new');
        $this->createTestOrder('User 3', 'confirmed');
        $this->createTestOrder('User 4', 'processing');
        $this->createTestOrder('User 5', 'ready');
        $this->createTestOrder('User 6', 'completed');
        $this->createTestOrder('User 7', 'cancelled');

        $response = $this->dispatch('GET', '/api/v1/admin/orders/summary', null, true);
        self::assertSame(200, $response->status);
        $body = $this->object($response->body);
        $data = $this->object($body['data'] ?? null);
        $summary = $this->object($data['summary'] ?? null);

        self::assertSame(2, $summary['new']);
        self::assertSame(1, $summary['confirmed']);
        self::assertSame(1, $summary['processing']);
        self::assertSame(1, $summary['ready']);
        self::assertSame(1, $summary['completed']);
        self::assertSame(1, $summary['cancelled']);
        self::assertSame(7, $summary['total']);
    }

    public function testOrderDetailRetrievalAndNotFound(): void
    {
        $orderId = $this->createTestOrder('Diana Prince', 'new', 15000, '2026-08-10 10:00:00', 'SYNC-DIANA-01');

        $response = $this->dispatch('GET', "/api/v1/admin/orders/{$orderId}", null, true);
        self::assertSame(200, $response->status);
        $body = $this->object($response->body);
        $data = $this->object($body['data'] ?? null);
        $order = $this->object($data['order'] ?? null);

        self::assertSame($orderId, $order['id']);
        self::assertSame('SYNC-DIANA-01', $order['reference']);
        self::assertSame('Diana Prince', $order['customer_name']);
        $items = $this->items($order['items'] ?? null);
        self::assertCount(1, $items);
        self::assertSame('Order Test Item', $items[0]['product_title']);
        self::assertArrayHasKey('status_history', $order);
        self::assertArrayNotHasKey('confirmation_token_hash', $order);
        self::assertArrayNotHasKey('idempotency_key_hash', $order);

        // Lookup by reference works too
        $refResponse = $this->dispatch('GET', '/api/v1/admin/orders/SYNC-DIANA-01', null, true);
        self::assertSame(200, $refResponse->status);
        $refBody = $this->object($refResponse->body);
        $refData = $this->object($refBody['data'] ?? null);
        $refOrder = $this->object($refData['order'] ?? null);
        self::assertSame($orderId, $refOrder['id']);

        // Unknown order returns 404
        $notFound = $this->dispatch('GET', '/api/v1/admin/orders/unknown-id', null, true);
        self::assertSame(404, $notFound->status);
        $notFoundBody = $this->object($notFound->body);
        $notFoundError = $this->object($notFoundBody['error'] ?? null);
        self::assertSame('ORDER_NOT_FOUND', $notFoundError['code']);
    }

    public function testOrderLifecycleValidTransitionsAndStatusHistory(): void
    {
        $orderId = $this->createTestOrder('Evan Wright', 'new');

        // 1. new -> confirmed
        $res1 = $this->dispatch('PATCH', "/api/v1/admin/orders/{$orderId}/status", ['status' => 'confirmed'], true);
        self::assertSame(200, $res1->status);
        $body1 = $this->object($res1->body);
        $meta1 = $this->object($body1['meta'] ?? null);
        $order1 = $this->object($this->object($body1['data'] ?? null)['order'] ?? null);
        self::assertSame('confirmed', $order1['fulfilment_status']);
        self::assertFalse($meta1['idempotent_replay']);
        $history1 = $this->items($order1['status_history'] ?? null);
        self::assertCount(1, $history1);
        self::assertSame('new', $history1[0]['previous_status']);
        self::assertSame('confirmed', $history1[0]['new_status']);
        self::assertSame(self::ADMIN_ID, $history1[0]['changed_by']);

        // 2. confirmed -> processing
        $res2 = $this->dispatch('PATCH', "/api/v1/admin/orders/{$orderId}/status", ['status' => 'processing'], true);
        self::assertSame(200, $res2->status);
        $body2 = $this->object($res2->body);
        $order2 = $this->object($this->object($body2['data'] ?? null)['order'] ?? null);
        self::assertSame('processing', $order2['fulfilment_status']);
        $history2 = $this->items($order2['status_history'] ?? null);
        self::assertCount(2, $history2);

        // 3. processing -> ready
        $res3 = $this->dispatch('PATCH', "/api/v1/admin/orders/{$orderId}/status", ['status' => 'ready'], true);
        self::assertSame(200, $res3->status);
        $body3 = $this->object($res3->body);
        $order3 = $this->object($this->object($body3['data'] ?? null)['order'] ?? null);
        self::assertSame('ready', $order3['fulfilment_status']);

        // 4. ready -> completed
        $res4 = $this->dispatch('PATCH', "/api/v1/admin/orders/{$orderId}/status", ['status' => 'completed'], true);
        self::assertSame(200, $res4->status);
        $body4 = $this->object($res4->body);
        $order4 = $this->object($this->object($body4['data'] ?? null)['order'] ?? null);
        self::assertSame('completed', $order4['fulfilment_status']);
        $history4 = $this->items($order4['status_history'] ?? null);
        self::assertCount(4, $history4);

        // Verify status notifications were enqueued
        $jobsStatement = $this->db->prepare('SELECT recipient_type, status FROM notification_jobs WHERE order_id = :id');
        $jobsStatement->execute(['id' => $orderId]);
        $jobs = $jobsStatement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(4, $jobs);
        $recipientTypes = array_column($jobs, 'recipient_type');
        self::assertContains('customer_status_confirmed', $recipientTypes);
        self::assertContains('customer_status_processing', $recipientTypes);
        self::assertContains('customer_status_ready', $recipientTypes);
        self::assertContains('customer_status_completed', $recipientTypes);
    }

    public function testTerminalStateCannotBeReopenedAndInvalidTransitionsAreRejected(): void
    {
        $orderId = $this->createTestOrder('Frank Miller', 'completed');

        // Completed order cannot transition back to processing or new
        $res = $this->dispatch('PATCH', "/api/v1/admin/orders/{$orderId}/status", ['status' => 'processing'], true);
        self::assertSame(409, $res->status);
        $body = $this->object($res->body);
        $error = $this->object($body['error'] ?? null);
        self::assertSame('INVALID_STATUS_TRANSITION', $error['code']);

        // Cancelled order cannot transition to confirmed
        $cancelledId = $this->createTestOrder('Grace Hopper', 'cancelled');
        $resCancel = $this->dispatch('PATCH', "/api/v1/admin/orders/{$cancelledId}/status", ['status' => 'confirmed'], true);
        self::assertSame(409, $resCancel->status);
        $cancelBody = $this->object($resCancel->body);
        $cancelError = $this->object($cancelBody['error'] ?? null);
        self::assertSame('INVALID_STATUS_TRANSITION', $cancelError['code']);

        // Direct jump from new -> completed is invalid
        $newId = $this->createTestOrder('Hannah Abbott', 'new');
        $resSkip = $this->dispatch('PATCH', "/api/v1/admin/orders/{$newId}/status", ['status' => 'completed'], true);
        self::assertSame(409, $resSkip->status);
        $skipBody = $this->object($resSkip->body);
        $skipError = $this->object($skipBody['error'] ?? null);
        self::assertSame('INVALID_STATUS_TRANSITION', $skipError['code']);
    }

    public function testSameStatusUpdateIsIdempotent(): void
    {
        $orderId = $this->createTestOrder('Ian Malcolm', 'confirmed');

        // Request confirmed on already confirmed order
        $response = $this->dispatch('PATCH', "/api/v1/admin/orders/{$orderId}/status", ['status' => 'confirmed'], true);
        self::assertSame(200, $response->status);
        $body = $this->object($response->body);
        $meta = $this->object($body['meta'] ?? null);
        $order = $this->object($this->object($body['data'] ?? null)['order'] ?? null);
        self::assertTrue($meta['idempotent_replay']);
        self::assertSame('confirmed', $order['fulfilment_status']);
        $history = $this->items($order['status_history'] ?? null);
        self::assertCount(0, $history);

        // Verify zero notification jobs were created
        $jobsStatement = $this->db->prepare('SELECT COUNT(*) FROM notification_jobs WHERE order_id = :id');
        $jobsStatement->execute(['id' => $orderId]);
        self::assertSame(0, (int) $jobsStatement->fetchColumn());
    }

    private function createTestOrder(
        string $customerName,
        string $status = 'new',
        int $subtotal = 5000,
        string $createdAt = '2026-08-17 12:00:00',
        ?string $reference = null,
        string $email = 'customer@example.com',
        string $phone = '+2348035732952',
    ): string {
        $orderId = UuidGenerator::v4();
        $ref = $reference ?? ('SYNC-' . substr($orderId, 0, 8));
        $deliveryFee = 5000;
        $total = $subtotal + $deliveryFee;

        $statement = $this->db->prepare(
            'INSERT INTO orders (id, reference, confirmation_token_hash, idempotency_key_hash, request_fingerprint, customer_name, phone_number, customer_email, fulfilment_method, delivery_address, state, subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, fulfilment_status, created_at, updated_at) '
            . 'VALUES (:id, :reference, :token_hash, :idemp_hash, :fingerprint, :name, :phone, :email, \'delivery\', \'12 Test St\', \'Lagos\', :subtotal, :delivery_fee, :total, \'NGN\', \'cash_on_delivery\', \'unpaid\', :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $orderId,
            'reference' => $ref,
            'token_hash' => hash('sha256', 'test-token-' . $orderId),
            'idemp_hash' => hash('sha256', 'test-idemp-' . $orderId),
            'fingerprint' => hash('sha256', 'fingerprint-' . $orderId),
            'name' => $customerName,
            'phone' => $phone,
            'email' => $email,
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $itemStatement = $this->db->prepare(
            'INSERT INTO order_items (id, order_id, product_id, product_public_id, product_title, product_slug, unit_price_kobo, quantity, line_total_kobo, created_at) '
            . 'VALUES (:id, :order_id, :product_id, :product_public_id, \'Order Test Item\', \'order-test-item\', :unit_price, 1, :line_total, :created_at)'
        );
        $itemStatement->execute([
            'id' => UuidGenerator::v4(),
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'product_public_id' => $this->productPublicId,
            'unit_price' => $subtotal,
            'line_total' => $subtotal,
            'created_at' => $createdAt,
        ]);

        return $orderId;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function dispatch(string $method, string $uri, ?array $body = null, bool $authenticated = true): HttpResponse
    {
        $this->currentBody = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : '';

        $parsedUri = parse_url($uri);
        $path = is_array($parsedUri) && is_string($parsedUri['path'] ?? null) ? $parsedUri['path'] : '/';
        $queryString = is_array($parsedUri) && is_string($parsedUri['query'] ?? null) ? $parsedUri['query'] : '';

        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $queryString,
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
        ];

        if ($authenticated) {
            $token = $this->jwt->issue(self::ADMIN_ID)['access_token'];
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $requestId = 'test_req_' . substr(UuidGenerator::v4(), 0, 8);

        if ($method === 'GET' && $path === '/api/v1/admin/orders') {
            return $this->controller->list($requestId, $server);
        }

        if ($method === 'GET' && $path === '/api/v1/admin/orders/summary') {
            return $this->controller->summary($requestId, $server);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/admin/orders/([^/]+)$#', $path, $matches) === 1) {
            return $this->controller->detail($requestId, $server, ['id' => $matches[1]]);
        }

        if ($method === 'PATCH' && preg_match('#^/api/v1/admin/orders/([^/]+)/status$#', $path, $matches) === 1) {
            return $this->controller->updateStatus($requestId, $server, ['id' => $matches[1]]);
        }

        throw new \InvalidArgumentException("Unhandled test route {$method} {$uri}");
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected response object.');
        }
        $result = [];
        foreach ($value as $field => $fieldValue) {
            if (!is_string($field)) {
                self::fail('Expected string response fields.');
            }
            $result[$field] = $fieldValue;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function items(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected response items list.');
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = $this->object($item);
        }

        return $result;
    }
}
