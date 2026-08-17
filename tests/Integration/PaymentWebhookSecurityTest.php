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
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\Paystack\PaystackClient;
use ProjectSync\Infrastructure\Paystack\PaystackHttpTransportInterface;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\PaymentAttemptRepository;
use ProjectSync\Repositories\PaymentEventRepository;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\PaymentFinalizationService;
use ProjectSync\Services\PaymentReferenceGenerator;
use ProjectSync\Services\PaymentService;
use Psr\Log\AbstractLogger;

final class PaymentWebhookSecurityTest extends TestCase
{
    private PDO $db;
    private Application $application;
    private string $paystackSecret = 'sk_test_webhook_sec_key_123456789';
    private string $rawBody = '';

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
        $attempts = new PaymentAttemptRepository($this->db);
        $events = new PaymentEventRepository($this->db);
        $paystack = new PaystackClient($this->paystackSecret, 'https://api.paystack.co', 10, $mockTransport);
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

    public function testMissingSignatureRejected(): void
    {
        $this->rawBody = '{"event":"charge.success","data":{}}';

        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
        ]);

        self::assertSame(401, $response->status);
        /** @var array{error: array{code: string}} $payload */
        $payload = $response->body;
        self::assertSame('UNAUTHORIZED', $payload['error']['code']);
    }

    public function testInvalidSignatureRejected(): void
    {
        $this->rawBody = '{"event":"charge.success","data":{}}';

        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid-hmac-signature',
        ]);

        self::assertSame(401, $response->status);
        /** @var array{error: array{code: string}} $payload */
        $payload = $response->body;
        self::assertSame('UNAUTHORIZED', $payload['error']['code']);
    }

    public function testRawBytesPreservationAndWhitespaceSensitivity(): void
    {
        $canonicalJson = '{"event":"transfer.success","data":{"amount":5000}}';
        $canonicalSig = hash_hmac('sha512', $canonicalJson, $this->paystackSecret);

        // 1. Exact raw bytes match -> success
        $this->rawBody = $canonicalJson;
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => $canonicalSig,
        ]);
        self::assertSame(200, $response->status);
        /** @var array{data: array{status: string}} $payload */
        $payload = $response->body;
        self::assertSame('ignored', $payload['data']['status']);

        // 2. Added whitespace or re-encoding changes raw bytes -> fails signature verification
        $whitespacePayload = '{"event":"transfer.success", "data":{"amount":5000}}'; // note space after colon
        $this->rawBody = $whitespacePayload;
        $responseFails = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => $canonicalSig, // signed for canonical, not whitespace version
        ]);
        self::assertSame(401, $responseFails->status);
    }

    public function testMalformedJsonWithValidSignatureReturns400(): void
    {
        $malformedBody = '{"event": "charge.success", "data": INVALID_JSON_SYNTAX}';
        $signature = hash_hmac('sha512', $malformedBody, $this->paystackSecret);

        $this->rawBody = $malformedBody;
        $response = $this->application->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/payments/paystack/webhook',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ]);

        self::assertSame(400, $response->status);
        /** @var array{error: array{code: string}} $payload */
        $payload = $response->body;
        self::assertSame('BAD_REQUEST', $payload['error']['code']);
    }
}
