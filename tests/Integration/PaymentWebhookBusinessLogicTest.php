<?php

declare(strict_types=1);

namespace Tests\Integration;

use FastRoute\RouteCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\PaymentWebhookController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\Email\FakeEmailSender;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\Paystack\PaystackClient;
use ProjectSync\Infrastructure\Paystack\PaystackHttpTransportInterface;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\NotificationJobRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\PaymentAttemptRepository;
use ProjectSync\Repositories\PaymentEventRepository;
use ProjectSync\Services\NotificationProcessor;
use ProjectSync\Services\NotificationService;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\OrderEmailBuilder;
use ProjectSync\Services\PaymentFinalizationService;
use ProjectSync\Services\PaymentReferenceGenerator;
use ProjectSync\Services\PaymentService;
use ProjectSync\Services\WhatsAppHandoffService;
use Psr\Log\AbstractLogger;

final class PaymentWebhookBusinessLogicTest extends TestCase
{
    private PDO $db;
    private Application $application;
    private string $paystackSecret = 'sk_test_webhook_biz_secret_12345';
    private string $rawBody = '';
    private string $orderId;
    private string $orderRef;
    private string $internalPaymentRef;
    private string $attemptId;
    private NotificationJobRepository $jobRepo;

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
        (new MigrationRunner($this->db, $root . '/database/migrations'))->run();

        $this->db->exec('UPDATE business_profiles SET merchant_email_notifications_enabled = TRUE, customer_email_notifications_enabled = TRUE, order_notification_email = \'merchant@example.com\'');

        $this->orderId = UuidGenerator::v4();
        $this->orderRef = 'SYNC-WB-' . bin2hex(random_bytes(4));
        $this->internalPaymentRef = 'PAY-SYNC-' . bin2hex(random_bytes(10));
        $this->attemptId = UuidGenerator::v4();

        $stmtOrder = $this->db->prepare(
            'INSERT INTO orders (id, reference, customer_name, phone_number, customer_email, fulfilment_method, '
            . 'subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, '
            . 'fulfilment_status, confirmation_token_hash, idempotency_key_hash, created_at, updated_at) '
            . "VALUES (:id, :ref, 'Webhook Customer', '+2348009998877', 'webhook_cust@example.com', 'pickup', "
            . "18000, 0, 18000, 'NGN', 'paystack', 'pending', 'new', :token_hash, :idem_hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmtOrder->execute([
            'id' => $this->orderId,
            'ref' => $this->orderRef,
            'token_hash' => hash('sha256', 'token-seed-' . $this->orderId),
            'idem_hash' => hash('sha256', 'order-idem-' . $this->orderId),
        ]);

        $stmtAttempt = $this->db->prepare(
            'INSERT INTO payment_attempts (id, order_id, provider, internal_reference, provider_reference, '
            . 'idempotency_key_hash, expected_amount_kobo, currency, status, resolution_status, initiated_at, created_at, updated_at) '
            . "VALUES (:id, :order_id, 'paystack', :pay_ref, :prov_ref, 'hash123', 18000, 'NGN', 'pending', 'none', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmtAttempt->execute([
            'id' => $this->attemptId,
            'order_id' => $this->orderId,
            'pay_ref' => $this->internalPaymentRef,
            'prov_ref' => $this->internalPaymentRef,
        ]);

        $logger = new class extends AbstractLogger {
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
            }
        };

        $mockTransport = new class implements PaystackHttpTransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                return ['status' => 200, 'body' => ''];
            }
        };

        $orders = new OrderRepository($this->db);
        $items = new OrderItemRepository($this->db);
        $profiles = new BusinessProfileRepository($this->db);
        $this->jobRepo = new NotificationJobRepository($this->db);
        $sender = new FakeEmailSender();
        $builder = new OrderEmailBuilder();
        $processor = new NotificationProcessor($this->jobRepo, $orders, $items, $profiles, $sender, $builder, $logger, 300, 900);
        $notifications = new NotificationService($profiles, $this->jobRepo, $processor, new WhatsAppHandoffService(), $logger, 'sec-key-32-chars-long-test-12345', 5, false);

        $attempts = new PaymentAttemptRepository($this->db);
        $events = new PaymentEventRepository($this->db);
        $paystack = new PaystackClient($this->paystackSecret, 'https://api.paystack.co', 10, $mockTransport);
        $finalizer = new PaymentFinalizationService($this->db, $attempts, $events, $notifications, $logger);
        $service = new PaymentService(
            db: $this->db,
            orders: $orders,
            attempts: $attempts,
            events: $events,
            paystack: $paystack,
            finalizer: $finalizer,
            tokens: new OrderConfirmationTokenService('sec-key-32-chars-long-test-12345'),
            references: new PaymentReferenceGenerator(),
            securitySecret: 'sec-key-32-chars-long-test-12345',
            logger: $logger,
        );

        $controller = new PaymentWebhookController($service, fn (): string => $this->rawBody);

        $routes = static function (RouteCollector $router) use ($controller): void {
            $router->addRoute('POST', '/api/v1/payments/paystack/webhook', [$controller, 'handle']);
        };

        $this->application = new Application(
            config: new Config(['app.environment' => 'testing', 'app.debug' => true]),
            logger: $logger,
            routes: $routes,
            middleware: [],
        );
    }

    public function testValidChargeSuccessFinalizesPaymentAndEnqueuesNotifications(): void
    {
        $rawPayload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 1234567,
                'status' => 'success',
                'reference' => $this->internalPaymentRef,
                'amount' => 18000,
                'currency' => 'NGN',
                'channel' => 'card',
            ],
        ]);
        $payload = is_string($rawPayload) ? $rawPayload : '';
        $signature = hash_hmac('sha512', $payload, $this->paystackSecret);

        $this->rawBody = $payload;
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ]);

        self::assertSame(200, $response->status);
        /** @var array{success: bool, data: array{idempotent_replay: bool}} $body */
        $body = $response->body;
        self::assertTrue($body['success']);
        self::assertFalse($body['data']['idempotent_replay']);

        // Check database state
        $orderStmt = $this->db->query("SELECT payment_status, fulfilment_status FROM orders WHERE id = '{$this->orderId}'");
        self::assertNotFalse($orderStmt);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($order);
        self::assertSame('paid', $order['payment_status']);
        self::assertSame('new', $order['fulfilment_status']);

        $attemptStmt = $this->db->query("SELECT status, verified_amount_kobo, resolution_status FROM payment_attempts WHERE id = '{$this->attemptId}'");
        self::assertNotFalse($attemptStmt);
        $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($attempt);
        self::assertSame('successful', $attempt['status']);
        $rawVerified = $attempt['verified_amount_kobo'] ?? 0;
        $verifiedKobo = is_int($rawVerified) ? $rawVerified : (is_numeric($rawVerified) ? (int) $rawVerified : 0);
        self::assertSame(18000, $verifiedKobo);
        self::assertSame('none', $attempt['resolution_status']);



        // Check notification jobs enqueued
        $jobs = $this->jobRepo->stateForOrder($this->orderId);
        self::assertArrayHasKey('merchant_payment_received', $jobs);
        self::assertArrayHasKey('customer_payment_confirmed', $jobs);

        // Replaying same webhook returns idempotent success with no duplicate jobs
        $replayResponse = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ]);
        self::assertSame(200, $replayResponse->status);
        /** @var array{data: array{idempotent_replay: bool}} $replayBody */
        $replayBody = $replayResponse->body;
        self::assertTrue($replayBody['data']['idempotent_replay']);
    }

    public function testAmountMismatchFailsAndLeavesOrderUnpaid(): void
    {
        $rawPayload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 1234567,
                'status' => 'success',
                'reference' => $this->internalPaymentRef,
                'amount' => 5000, // order total is 18000!
                'currency' => 'NGN',
            ],
        ]);
        $payload = is_string($rawPayload) ? $rawPayload : '';
        $signature = hash_hmac('sha512', $payload, $this->paystackSecret);

        $this->rawBody = $payload;
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ]);

        self::assertSame(422, $response->status);
        /** @var array{error: array{code: string}} $body */
        $body = $response->body;
        self::assertSame('PAYMENT_AMOUNT_MISMATCH', $body['error']['code']);

        // Order remains pending, not marked paid
        $orderStmt = $this->db->query("SELECT payment_status FROM orders WHERE id = '{$this->orderId}'");
        self::assertNotFalse($orderStmt);
        self::assertSame('pending', $orderStmt->fetchColumn());
    }

    public function testLatePaymentOnCancelledOrderSetsRequiresAction(): void
    {
        // Cancel the order first
        $this->db->exec("UPDATE orders SET fulfilment_status = 'cancelled' WHERE id = '{$this->orderId}'");

        $rawPayload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 1234567,
                'status' => 'success',
                'reference' => $this->internalPaymentRef,
                'amount' => 18000,
                'currency' => 'NGN',
            ],
        ]);
        $payload = is_string($rawPayload) ? $rawPayload : '';
        $signature = hash_hmac('sha512', $payload, $this->paystackSecret);

        $this->rawBody = $payload;
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ]);

        self::assertSame(200, $response->status);

        // Fulfilment remains cancelled (never reopened), payment status becomes paid (financial truth)
        $orderStmt = $this->db->query("SELECT payment_status, fulfilment_status FROM orders WHERE id = '{$this->orderId}'");
        self::assertNotFalse($orderStmt);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($order);
        self::assertSame('paid', $order['payment_status']);
        self::assertSame('cancelled', $order['fulfilment_status']);

        // Attempt is successful but flagged requires_action
        $attemptStmt = $this->db->query("SELECT status, resolution_status FROM payment_attempts WHERE id = '{$this->attemptId}'");
        self::assertNotFalse($attemptStmt);
        $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($attempt);
        self::assertSame('successful', $attempt['status']);
        self::assertSame('requires_action', $attempt['resolution_status']);

        // Merchant late payment notification enqueued
        $jobs = $this->jobRepo->stateForOrder($this->orderId);
        self::assertArrayHasKey('merchant_late_payment_action', $jobs);
    }
}
