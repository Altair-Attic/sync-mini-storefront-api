<?php

declare(strict_types=1);

namespace Tests\Integration;

use FastRoute\RouteCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\PaymentController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\Paystack\PaystackClient;
use ProjectSync\Infrastructure\Paystack\PaystackHttpTransportInterface;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\PaymentAttemptRepository;
use ProjectSync\Repositories\PaymentEventRepository;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\PaymentFinalizationService;
use ProjectSync\Services\PaymentRateLimiter;
use ProjectSync\Services\PaymentReferenceGenerator;
use ProjectSync\Services\PaymentService;
use ProjectSync\Validators\PaymentInitializationValidator;
use Psr\Log\AbstractLogger;

final class PaymentInitializationApiTest extends TestCase
{
    private PDO $db;
    private Application $application;
    private OrderConfirmationTokenService $tokenService;
    private string $orderId;
    private string $orderRef;
    private string $rawToken;

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

        $this->tokenService = new OrderConfirmationTokenService();

        $this->orderId = UuidGenerator::v4();
        $this->orderRef = 'SYNC-PAY-' . bin2hex(random_bytes(4));
        $this->rawToken = $this->tokenService->generate();
        $tokenHash = $this->tokenService->tokenHash($this->rawToken);

        $stmt = $this->db->prepare(
            'INSERT INTO orders (id, reference, customer_name, phone_number, customer_email, fulfilment_method, '
            . 'subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, '
            . 'fulfilment_status, confirmation_token_hash, idempotency_key_hash, created_at, updated_at) '
            . "VALUES (:id, :ref, 'Pay Customer', '+2348001112233', 'customer@example.com', 'pickup', "
            . "25000, 0, 25000, 'NGN', 'paystack', 'unpaid', 'new', :token_hash, :idem_hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([
            'id' => $this->orderId,
            'ref' => $this->orderRef,
            'token_hash' => $tokenHash,
            'idem_hash' => hash('sha256', 'order-idem-' . $this->orderId),
        ]);

        $mockTransport = new class implements PaystackHttpTransportInterface {
            public int $callCount = 0;

            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                $this->callCount++;
                $json = json_encode([
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/auth_' . $this->callCount,
                        'access_code' => 'access_code_' . $this->callCount,
                        'reference' => 'PAY-SYNC-MOCK-' . $this->callCount,
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

        $orders = new OrderRepository($this->db);
        $attempts = new PaymentAttemptRepository($this->db);
        $events = new PaymentEventRepository($this->db);
        $paystack = new PaystackClient('sk_test_mock_key', 'https://api.paystack.co', 10, $mockTransport);
        $finalizer = new PaymentFinalizationService($this->db, $attempts, $events, null, $logger);
        $service = new PaymentService(
            db: $this->db,
            orders: $orders,
            attempts: $attempts,
            events: $events,
            paystack: $paystack,
            finalizer: $finalizer,
            tokens: $this->tokenService,
            references: new PaymentReferenceGenerator(),
            logger: $logger,
        );
        $rateLimiter = new PaymentRateLimiter(new LoginAttemptRepository($this->db), new Config([
            'checkout.max_attempts' => '30',
            'checkout.confirmation_max_attempts' => '30',
            'checkout.window_seconds' => '60',
            'checkout.block_seconds' => '300',
        ]));
        $validator = new PaymentInitializationValidator(200);
        $controller = new PaymentController($service, $validator, $rateLimiter);

        $routes = static function (RouteCollector $router) use ($controller): void {
            $router->addRoute('POST', '/api/v1/orders/{reference}/payments', [$controller, 'initialize']);
            $router->addRoute('GET', '/api/v1/orders/{reference}/payments/{paymentReference}', [$controller, 'status']);
        };

        $this->application = new Application(
            config: new Config(['app.environment' => 'testing', 'app.debug' => true]),
            logger: $logger,
            routes: $routes,
            middleware: [],
        );
    }

    public function testInitializePaymentSuccessfulAndIdempotentReplay(): void
    {
        $idempotencyKey = 'idem-key-init-12345678901234';

        // 1. Initial request
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/orders/{$this->orderRef}/payments",
            'HTTP_X_CONFIRMATION_TOKEN' => $this->rawToken,
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(201, $response->status);
        /** @var array{success: bool, meta: array{idempotent_replay: bool}, data: array{expected_amount_kobo: int, currency: string, status: string, payment_reference: string}} $payload */
        $payload = $response->body;
        self::assertTrue($payload['success']);
        self::assertFalse($payload['meta']['idempotent_replay']);
        self::assertSame(25000, $payload['data']['expected_amount_kobo']);
        self::assertSame('NGN', $payload['data']['currency']);
        self::assertSame('pending', $payload['data']['status']);
        self::assertStringStartsWith('PAY-SYNC-', $payload['data']['payment_reference']);
        $paymentRef = $payload['data']['payment_reference'];

        // 2. Exact replay with same idempotency key
        $replayResponse = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/orders/{$this->orderRef}/payments",
            'HTTP_X_CONFIRMATION_TOKEN' => $this->rawToken,
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(200, $replayResponse->status);
        /** @var array{success: bool, meta: array{idempotent_replay: bool}, data: array{payment_reference: string}} $replayPayload */
        $replayPayload = $replayResponse->body;
        self::assertTrue($replayPayload['success']);
        self::assertTrue($replayPayload['meta']['idempotent_replay']);
        self::assertSame($paymentRef, $replayPayload['data']['payment_reference']);

        // 3. Status check endpoint
        $statusResponse = $this->application->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => "/api/v1/orders/{$this->orderRef}/payments/{$paymentRef}",
            'HTTP_X_CONFIRMATION_TOKEN' => $this->rawToken,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(200, $statusResponse->status);
        /** @var array{success: bool, data: array{payment_reference: string, order_reference: string, amount_kobo: int}} $statusPayload */
        $statusPayload = $statusResponse->body;
        self::assertTrue($statusPayload['success']);
        self::assertSame($paymentRef, $statusPayload['data']['payment_reference']);
        self::assertSame($this->orderRef, $statusPayload['data']['order_reference']);
        self::assertSame(25000, $statusPayload['data']['amount_kobo']);
    }

    public function testCancelledOrderCannotInitializePayment(): void
    {
        $this->db->exec("UPDATE orders SET fulfilment_status = 'cancelled' WHERE id = '{$this->orderId}'");

        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/orders/{$this->orderRef}/payments",
            'HTTP_X_CONFIRMATION_TOKEN' => $this->rawToken,
            'HTTP_IDEMPOTENCY_KEY' => 'idem-key-cancelled-12345678',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(422, $response->status);
        /** @var array{error: array{code: string}} $payload */
        $payload = $response->body;
        self::assertSame('ORDER_CANCELLED', $payload['error']['code']);
    }

    public function testPaidOrderCannotInitializePayment(): void
    {
        $this->db->exec("UPDATE orders SET payment_status = 'paid' WHERE id = '{$this->orderId}'");

        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/orders/{$this->orderRef}/payments",
            'HTTP_X_CONFIRMATION_TOKEN' => $this->rawToken,
            'HTTP_IDEMPOTENCY_KEY' => 'idem-key-paid-123456789012',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(409, $response->status);
        /** @var array{error: array{code: string}} $payload */
        $payload = $response->body;
        self::assertSame('ALREADY_PAID', $payload['error']['code']);
    }

    public function testMissingIdempotencyKeyRejected(): void
    {
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => "/api/v1/orders/{$this->orderRef}/payments",
            'HTTP_X_CONFIRMATION_TOKEN' => $this->rawToken,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(400, $response->status);
        /** @var array{error: array{code: string}} $payload */
        $payload = $response->body;
        self::assertSame('IDEMPOTENCY_KEY_REQUIRED', $payload['error']['code']);
    }
}
