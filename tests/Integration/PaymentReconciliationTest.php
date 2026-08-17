<?php

declare(strict_types=1);

namespace Tests\Integration;

use FastRoute\RouteCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\Admin\AdminPaymentController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\Paystack\PaystackClient;
use ProjectSync\Infrastructure\Paystack\PaystackHttpTransportInterface;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\PaymentAttemptRepository;
use ProjectSync\Repositories\PaymentEventRepository;
use ProjectSync\Repositories\RevokedAccessTokenRepository;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\PaymentFinalizationService;
use ProjectSync\Services\PaymentRateLimiter;
use ProjectSync\Services\PaymentReferenceGenerator;
use ProjectSync\Services\PaymentService;
use Psr\Log\AbstractLogger;

class MockPaystackReconcileState
{
    public string $statusToReturn = 'success';
}

final class PaymentReconciliationTest extends TestCase
{
    private PDO $db;
    private Application $application;
    private JwtService $jwt;
    private string $merchantId;
    private string $accessToken;
    private string $orderId;
    private string $paymentId;
    private string $paymentRef;
    private string $orderRef;
    private MockPaystackReconcileState $mockState;


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

        $this->jwt = new JwtService(
            secret: 'test-jwt-secret-key-32-chars-minimum!',
            issuer: 'https://test.project-sync.local',
            audience: 'https://test.project-sync.local',
            ttlSeconds: 900,
            clockSkewSeconds: 30,
            algorithm: 'HS256',
        );

        $this->merchantId = UuidGenerator::v4();
        $stmtUser = $this->db->prepare(
            'INSERT INTO merchant_users (id, name, email, password_hash, status, created_at, updated_at) '
            . "VALUES (:id, 'Admin Rec', :email, 'hash', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmtUser->execute([
            'id' => $this->merchantId,
            'email' => 'admin-rec-' . $this->merchantId . '@example.com',
        ]);

        $this->accessToken = $this->jwt->issue($this->merchantId)['access_token'];

        $this->orderId = UuidGenerator::v4();
        $this->paymentId = UuidGenerator::v4();
        $this->paymentRef = 'PAY-SYNC-' . bin2hex(random_bytes(10));
        $this->orderRef = 'SYNC-REC-' . bin2hex(random_bytes(4));

        $stmtOrder = $this->db->prepare(
            'INSERT INTO orders (id, reference, customer_name, phone_number, customer_email, fulfilment_method, '
            . 'subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, '
            . 'fulfilment_status, confirmation_token_hash, idempotency_key_hash, created_at, updated_at) '
            . "VALUES (:id, :ref, 'Rec Customer', '+2348001112233', 'rec@example.com', 'pickup', "
            . "30000, 0, 30000, 'NGN', 'paystack', 'pending', 'new', :token_hash, :idem_hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmtOrder->execute([
            'id' => $this->orderId,
            'ref' => $this->orderRef,
            'token_hash' => hash('sha256', 'rec-token-seed-' . $this->orderId),
            'idem_hash' => hash('sha256', 'order-idem-' . $this->orderId),
        ]);

        $stmtAttempt = $this->db->prepare(
            'INSERT INTO payment_attempts (id, order_id, provider, internal_reference, provider_reference, '
            . 'idempotency_key_hash, expected_amount_kobo, currency, status, resolution_status, initiated_at, created_at, updated_at) '
            . "VALUES (:id, :order_id, 'paystack', :pay_ref, :prov_ref, 'hash123', 30000, 'NGN', 'pending', 'none', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmtAttempt->execute([
            'id' => $this->paymentId,
            'order_id' => $this->orderId,
            'pay_ref' => $this->paymentRef,
            'prov_ref' => $this->paymentRef,
        ]);

        $this->mockState = new MockPaystackReconcileState();

        $mockTransport = new class($this->mockState) implements PaystackHttpTransportInterface {
            public function __construct(private MockPaystackReconcileState $state)
            {
            }


            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                $json = json_encode([
                    'status' => true,
                    'message' => 'Verification successful',
                    'data' => [
                        'status' => $this->state->statusToReturn,
                        'reference' => 'PAY-SYNC-REF',
                        'amount' => 30000,
                        'currency' => 'NGN',
                        'channel' => 'card',
                    ],
                ]);

                return [
                    'status' => 200,
                    'body' => is_string($json) ? $json : '',
                ];
            }
        };

        $logger = new class extends AbstractLogger {
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
            }
        };

        $users = new MerchantUserRepository($this->db);
        $revokedTokens = new RevokedAccessTokenRepository($this->db);
        $authMiddleware = new AuthenticationMiddleware($this->jwt, $users, $revokedTokens);

        $orders = new OrderRepository($this->db);
        $attempts = new PaymentAttemptRepository($this->db);
        $events = new PaymentEventRepository($this->db);
        $paystack = new PaystackClient('sk_test_rec_key', 'https://api.paystack.co', 10, $mockTransport);
        $finalizer = new PaymentFinalizationService($this->db, $attempts, $events, null, $logger);
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

        $rateLimiter = new PaymentRateLimiter(new LoginAttemptRepository($this->db), new Config([
            'checkout.max_attempts' => '30',
            'checkout.confirmation_max_attempts' => '30',
            'checkout.window_seconds' => '60',
            'checkout.block_seconds' => '300',
            'checkout.security_secret' => 'sec-key-32-chars-long-test-12345',
        ]));

        $controller = new AdminPaymentController($authMiddleware, $orders, $attempts, $events, $service, $rateLimiter);

        $routes = static function (RouteCollector $router) use ($controller): void {
            $router->addRoute('GET', '/api/v1/admin/orders/{orderId}/payments', [$controller, 'list']);
            $router->addRoute('POST', '/api/v1/admin/orders/{orderId}/payments/{paymentId}/reconcile', [$controller, 'reconcile']);
        };

        $this->application = new Application(
            config: new Config(['app.environment' => 'testing', 'app.debug' => true]),
            logger: $logger,
            routes: $routes,
            middleware: [],
        );
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM payment_events');
        $this->db->exec('DELETE FROM payment_attempts');
        $this->db->exec('DELETE FROM orders');
        $this->db->exec("DELETE FROM merchant_users WHERE email LIKE 'admin-rec-%'");
    }

    public function testAdminReconcileRequiresJwt(): void
    {
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/admin/orders/{$this->orderId}/payments/{$this->paymentId}/reconcile",
        ]);

        self::assertSame(401, $response->status);
    }

    public function testAdminReconcileSuccessfulVerificationMarksOrderPaid(): void
    {
        $this->mockState->statusToReturn = 'success';

        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/admin/orders/{$this->orderId}/payments/{$this->paymentId}/reconcile",
            'HTTP_AUTHORIZATION' => "Bearer {$this->accessToken}",
        ]);

        self::assertSame(200, $response->status);
        /** @var array{success: bool, data: array{verified: bool, status: string, payment_status: string}} $payload */
        $payload = $response->body;
        self::assertTrue($payload['success']);
        self::assertTrue($payload['data']['verified']);
        self::assertSame('successful', $payload['data']['status']);
        self::assertSame('paid', $payload['data']['payment_status']);

        // Check database
        $orderStmt = $this->db->query("SELECT payment_status FROM orders WHERE id = '{$this->orderId}'");
        self::assertNotFalse($orderStmt);
        self::assertSame('paid', $orderStmt->fetchColumn());
    }

    public function testAdminReconcileAbandonedProviderTransactionUpdatesStatus(): void
    {
        $this->mockState->statusToReturn = 'abandoned';

        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/admin/orders/{$this->orderId}/payments/{$this->paymentId}/reconcile",
            'HTTP_AUTHORIZATION' => "Bearer {$this->accessToken}",
        ]);

        self::assertSame(200, $response->status);
        /** @var array{success: bool, data: array{verified: bool, status: string}} $payload */
        $payload = $response->body;
        self::assertTrue($payload['success']);
        self::assertFalse($payload['data']['verified']);
        self::assertSame('abandoned', $payload['data']['status']);

        // Order remains unpaid/pending
        $orderStmt = $this->db->query("SELECT payment_status FROM orders WHERE id = '{$this->orderId}'");
        self::assertNotFalse($orderStmt);
        self::assertSame('pending', $orderStmt->fetchColumn());
    }

    public function testAdminPaymentInspectionReturnsPaymentListAndEvents(): void
    {
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => "/api/v1/admin/orders/{$this->orderId}/payments",
            'HTTP_AUTHORIZATION' => "Bearer {$this->accessToken}",
        ]);

        self::assertSame(200, $response->status);
        /** @var array{success: bool, data: array{order_id: string, payments: list<array{internal_reference: string}>}} $payload */
        $payload = $response->body;
        self::assertTrue($payload['success']);
        self::assertSame($this->orderId, $payload['data']['order_id']);
        self::assertCount(1, $payload['data']['payments']);
        self::assertSame($this->paymentRef, $payload['data']['payments'][0]['internal_reference']);
    }
}
