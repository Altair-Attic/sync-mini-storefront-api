<?php

declare(strict_types=1);

namespace Tests\Integration;

use FastRoute\RouteCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\OrderConfirmationController;
use ProjectSync\Controllers\OrderController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\ProductRepository;
use ProjectSync\Repositories\NotificationJobRepository;
use ProjectSync\Services\CheckoutRateLimiter;
use ProjectSync\Services\CheckoutService;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\OrderReferenceGenerator;
use ProjectSync\Services\NotificationProcessor;
use ProjectSync\Services\NotificationService;
use ProjectSync\Services\OrderEmailBuilder;
use ProjectSync\Services\WhatsAppHandoffService;
use ProjectSync\Infrastructure\Email\FakeEmailSender;
use ProjectSync\Validators\CheckoutValidator;
use Psr\Log\AbstractLogger;

final class OrderApiTest extends TestCase
{
    private PDO $db;
    private Application $application;
    private string $body = '';
    private string $productId;
    private string $productPublicId;

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
        $this->db->exec('UPDATE business_profiles SET delivery_enabled = TRUE, pickup_enabled = TRUE, fixed_delivery_fee_kobo = 5000');
        $this->productId = UuidGenerator::v4();
        $this->productPublicId = UuidGenerator::v4();
        $insert = $this->db->prepare('INSERT INTO products (id, public_id, slug, title, price_kobo, is_active, created_at, updated_at) VALUES (:id, :public_id, :slug, \'API Product\', 10000, TRUE, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $insert->execute(['id' => $this->productId, 'public_id' => $this->productPublicId, 'slug' => 'order-api-' . substr($this->productId, 0, 8)]);

        $config = new Config([
            'app.environment' => 'testing', 'app.debug' => false, 'cors.allowed_origins' => [],
            'checkout.security_secret' => str_repeat('api-contract-secret-', 3),
            'checkout.max_attempts' => '30', 'checkout.confirmation_max_attempts' => '20',
            'checkout.window_seconds' => '60', 'checkout.block_seconds' => '300',
        ]);
        $orders = new OrderRepository($this->db);
        $items = new OrderItemRepository($this->db);
        $jobs = new NotificationJobRepository($this->db);
        $failingSender = new FakeEmailSender(true);
        $processor = new NotificationProcessor($jobs, $orders, $items, new BusinessProfileRepository($this->db), $failingSender, new OrderEmailBuilder(), new \Psr\Log\NullLogger(), 300, 900);
        $notifications = new NotificationService(
            new BusinessProfileRepository($this->db), $jobs, $processor, new WhatsAppHandoffService(),
            new \Psr\Log\NullLogger(), str_repeat('api-notification-secret-', 3), 5, true,
        );
        $service = new CheckoutService(
            $this->db, new BusinessProfileRepository($this->db), new ProductRepository($this->db),
            $orders, $items, new OrderReferenceGenerator(),
            new OrderConfirmationTokenService($config->requiredString('checkout.security_secret')), 4_294_967_295, $notifications,
        );
        $limiter = new CheckoutRateLimiter(new LoginAttemptRepository($this->db), $config);
        $ordersController = new OrderController(
            new CheckoutValidator(50, 100, 200), $service, $limiter,
            function (): string { return $this->body; },
        );
        $confirmation = new OrderConfirmationController($service, $limiter);
        $routes = static function (RouteCollector $router) use ($ordersController, $confirmation): void {
            $router->addRoute('POST', '/api/v1/orders', [$ordersController, 'create']);
            $router->addRoute('GET', '/api/v1/orders/{reference}/confirmation', [$confirmation, 'show']);
        };
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $exception = $context['exception'] ?? null;
                if ($exception instanceof \Throwable) {
                    throw $exception;
                }
            }
        };
        $this->application = new Application($config, $logger, $routes, [new RequestIdMiddleware(), new CorsMiddleware([])]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM order_items WHERE product_id = :id')->execute(['id' => $this->productId]);
        $this->db->prepare("DELETE FROM orders WHERE customer_name = 'API Contract Customer'")->execute();
        $this->db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
    }

    public function testPublicCheckoutReplayAndConfirmationContracts(): void
    {
        $this->body = json_encode([
            'customer_name' => 'API Contract Customer', 'phone_number' => '+2349035732952', 'customer_email' => null,
            'fulfilment_method' => 'delivery', 'delivery_address' => '12 Contract Street', 'state' => 'Ogun',
            'payment_method' => 'cash_on_delivery', 'items' => [['product_id' => $this->productPublicId, 'quantity' => 2]],
        ], JSON_THROW_ON_ERROR);
        $server = [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/orders', 'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'api-contract-idempotency-001', 'REMOTE_ADDR' => '192.0.2.123',
        ];
        $created = $this->application->handle($server);
        self::assertSame(201, $created->status);
        self::assertTrue($created->body['success']);
        $meta = $this->object($created->body['meta'] ?? null);
        self::assertSame(false, $meta['idempotent_replay']);
        $data = $this->object($created->body['data'] ?? null);
        self::assertSame(25000, $data['total_kobo']);
        self::assertArrayNotHasKey('id', $data);
        self::assertArrayNotHasKey('phone_number', $data);
        $notification = $this->object($data['notification'] ?? null);
        self::assertSame('queued', $notification['merchant_email']);
        self::assertSame('skipped', $notification['customer_email']);
        self::assertArrayNotHasKey('error', $notification);
        self::assertArrayNotHasKey('job_id', $notification);

        $replayed = $this->application->handle($server);
        self::assertSame(200, $replayed->status);
        $replayMeta = $this->object($replayed->body['meta'] ?? null);
        self::assertSame(true, $replayMeta['idempotent_replay']);
        $replayData = $this->object($replayed->body['data'] ?? null);
        self::assertSame($data['reference'], $replayData['reference']);
        self::assertSame($data['confirmation_token'], $replayData['confirmation_token']);

        $reference = $data['reference'] ?? null;
        $token = $data['confirmation_token'] ?? null;
        self::assertIsString($reference);
        self::assertIsString($token);
        $confirmed = $this->application->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/orders/' . rawurlencode($reference) . '/confirmation?token=' . rawurlencode($token),
            'REMOTE_ADDR' => '192.0.2.123',
        ]);
        self::assertSame(200, $confirmed->status);
        $confirmationData = $this->object($confirmed->body['data'] ?? null);
        self::assertArrayNotHasKey('confirmation_token', $confirmationData);
        self::assertArrayNotHasKey('idempotency_key_hash', $confirmationData);
        self::assertArrayHasKey('whatsapp_url', $confirmationData);
    }

    public function testCheckoutRequiresJsonAndIdempotencyWithoutAdminSessionOrCsrf(): void
    {
        $this->body = '{}';
        $media = $this->application->handle(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/orders', 'REMOTE_ADDR' => '192.0.2.124']);
        self::assertSame(415, $media->status);

        $this->body = json_encode([
            'customer_name' => 'API Contract Customer', 'phone_number' => '+2349035732952', 'customer_email' => null,
            'fulfilment_method' => 'pickup', 'delivery_address' => null, 'state' => null,
            'payment_method' => 'cash_on_delivery', 'items' => [['product_id' => $this->productPublicId, 'quantity' => 1]],
        ], JSON_THROW_ON_ERROR);
        $missingKey = $this->application->handle([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/orders', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.124',
        ]);
        self::assertSame(400, $missingKey->status);
        $error = $this->object($missingKey->body['error'] ?? null);
        self::assertSame('IDEMPOTENCY_KEY_REQUIRED', $error['code']);
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
}
