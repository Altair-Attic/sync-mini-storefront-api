<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\BusinessProfileController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\Session\SessionManager;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\CsrfMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Services\BusinessProfileService;
use ProjectSync\Services\CsrfTokenService;
use ProjectSync\Services\LoginRateLimiter;
use ProjectSync\Validators\BusinessProfileValidator;
use Psr\Log\AbstractLogger;

final class BusinessProfileApiTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        session_id('');
        parent::tearDown();
    }

    public function testPublicStoreProfileCanBeRetrievedWithoutAuthenticationAndOnlyHasSafeFields(): void
    {
        $profile = $this->profile();
        $response = $this->request($profile, false, 'GET', '/api/v1/store');
        $responseProfile = $this->responseProfile($response);

        self::assertSame(200, $response->status);
        self::assertSame([
            'business_name', 'slug', 'domain', 'whatsapp_number', 'support_email', 'logo_url', 'template_id', 'currency', 'timezone', 'delivery_enabled', 'pickup_enabled', 'fixed_delivery_fee_kobo',
        ], array_keys($responseProfile));
        self::assertArrayNotHasKey('id', $responseProfile);
        self::assertArrayNotHasKey('order_confirmation_email', $responseProfile);
        self::assertArrayNotHasKey('order_notification_email', $responseProfile);
        self::assertArrayNotHasKey('merchant_email_notifications_enabled', $responseProfile);
    }

    public function testAdminProfileRequiresAuthentication(): void
    {
        $profile = $this->profile();
        $response = $this->request($profile, false, 'GET', '/api/v1/admin/profile');

        self::assertSame(401, $response->status);
        self::assertSame('UNAUTHENTICATED', $this->responseError($response)['code']);
    }

    public function testAuthenticatedAdministratorCanRetrieveCompleteSafeProfile(): void
    {
        $profile = $this->profile();
        $expectedKeys = array_keys($profile);
        $response = $this->request($profile, true, 'GET', '/api/v1/admin/profile');
        $responseProfile = $this->responseProfile($response);

        self::assertSame(200, $response->status);
        self::assertSame($expectedKeys, array_keys($responseProfile));
        self::assertArrayNotHasKey('password_hash', $responseProfile);
        self::assertArrayNotHasKey('order_confirmation_email', $responseProfile);
    }

    public function testValidProfileUpdateSucceedsAndNormalizesValues(): void
    {
        $profile = $this->profile();
        $input = $this->validInput();
        $input['whatsapp_number'] = '0803 573 2952';
        $input['support_email'] = ' SUPPORT@EXAMPLE.COM ';
        $input['order_notification_email'] = ' ORDERS@EXAMPLE.COM ';
        $response = $this->request($profile, true, 'PUT', '/api/v1/admin/profile', $input, true);
        $responseProfile = $this->responseProfile($response);

        self::assertSame(200, $response->status);
        self::assertSame('+2348035732952', $responseProfile['whatsapp_number']);
        self::assertSame('support@example.com', $responseProfile['support_email']);
        self::assertSame('orders@example.com', $responseProfile['order_notification_email']);
        self::assertTrue($responseProfile['merchant_email_notifications_enabled']);
        self::assertFalse($responseProfile['customer_email_notifications_enabled']);
        self::assertSame('demo-store', $responseProfile['slug']);
        self::assertSame('demo.example.com', $responseProfile['domain']);
    }

    public function testUpdateWithoutAuthenticationReturns401BeforeCsrfValidation(): void
    {
        $profile = $this->profile();
        $response = $this->request($profile, false, 'PUT', '/api/v1/admin/profile', $this->validInput());

        self::assertSame(401, $response->status);
    }

    public function testUpdateWithoutCsrfReturns403(): void
    {
        $profile = $this->profile();
        $response = $this->request($profile, true, 'PUT', '/api/v1/admin/profile', $this->validInput());

        self::assertSame(403, $response->status);
        self::assertSame('CSRF_TOKEN_INVALID', $this->responseError($response)['code']);
    }

    public function testMissingJsonContentTypeIsRejected(): void
    {
        $profile = $this->profile();
        $response = $this->request($profile, true, 'PUT', '/api/v1/admin/profile', $this->validInput(), true, false);

        self::assertSame(415, $response->status);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $this->responseError($response)['code']);
    }

    /** @param mixed $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUpdateFields')]
    public function testInvalidUpdateFieldsAreRejected(string $field, mixed $value): void
    {
        $profile = $this->profile();
        $input = $this->validInput();
        $input[$field] = $value;
        $response = $this->request($profile, true, 'PUT', '/api/v1/admin/profile', $input, true);

        self::assertSame(422, $response->status);
        $error = $this->responseError($response);
        self::assertSame('VALIDATION_FAILED', $error['code']);
        $fields = $error['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertArrayHasKey($field, $fields);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidUpdateFields(): iterable
    {
        yield 'invalid email' => ['support_email', 'bad'];
        yield 'invalid phone number' => ['whatsapp_number', '1234'];
        yield 'invalid currency' => ['currency', 'USD'];
        yield 'invalid timezone' => ['timezone', 'Invalid/Timezone'];
        yield 'invalid logo URL' => ['logo_url', 'http://example.com/logo.png'];
        yield 'unknown field' => ['unknown', 'value'];
        yield 'slug identity field' => ['slug', 'changed'];
        yield 'domain identity field' => ['domain', 'changed.example.com'];
    }

    public function testMissingProfileReturns404(): void
    {
        $profile = null;
        $response = $this->request($profile, false, 'GET', '/api/v1/store');

        self::assertSame(404, $response->status);
        self::assertSame('BUSINESS_PROFILE_NOT_FOUND', $this->responseError($response)['code']);
    }

    public function testDatabaseErrorsReturnProductionSafeResponses(): void
    {
        $profile = $this->profile();
        $response = $this->request($profile, false, 'GET', '/api/v1/store', databaseFailure: true);

        self::assertSame(500, $response->status);
        self::assertSame(['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.'], $this->responseError($response));
        self::assertStringNotContainsString('SQL', json_encode($response->body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string}|null $profile
     * @param array<string, mixed>|null $body
     */
    private function request(?array &$profile, bool $authenticated, string $method, string $uri, ?array $body = null, bool $csrfValid = false, bool $jsonContentType = true, bool $databaseFailure = false): \ProjectSync\Infrastructure\HttpResponse
    {
        $logger = new class ($databaseFailure) extends AbstractLogger {
            public function __construct(private readonly bool $allowError) {}
            /** @param array<string, mixed> $context */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $exception = $context['exception'] ?? null;
                if (!$this->allowError && $exception instanceof \Throwable) {
                    throw $exception;
                }
            }
        };
        $pdo = $this->pdo($profile, $databaseFailure);
        $config = new Config([
            'app.environment' => 'testing',
            'app.debug' => false,
            'cors.allowed_origins' => [],
            'session.name' => 'business_profile_test_' . bin2hex(random_bytes(4)),
            'session.lifetime' => '7200',
            'session.secure_cookie' => false,
            'session.same_site' => 'Lax',
            'session.domain' => '',
            'login.max_attempts' => '5',
            'login.window_seconds' => '900',
            'login.block_seconds' => '900',
            'login.rate_limit_secret' => 'test-secret',
        ]);
        $session = new SessionManager($config);
        $csrf = new CsrfTokenService($session, 3600);
        $authenticationService = new AuthenticationService(
            new MerchantUserRepository($pdo),
            new LoginRateLimiter(new LoginAttemptRepository($pdo), $config),
            $session,
            $csrf,
            $logger,
        );
        $authentication = new AuthenticationMiddleware($authenticationService);
        $csrfMiddleware = new CsrfMiddleware($csrf, $logger);
        $encodedBody = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $controller = new BusinessProfileController(
            new BusinessProfileService(new BusinessProfileRepository($pdo), new BusinessProfileValidator()),
            $authentication,
            $csrfMiddleware,
            static fn (): string => $encodedBody,
        );
        $routes = static function (\FastRoute\RouteCollector $router) use ($controller): void {
            $router->addRoute('GET', '/api/v1/store', [$controller, 'store']);
            $router->addRoute('GET', '/api/v1/admin/profile', [$controller, 'admin']);
            $router->addRoute('PUT', '/api/v1/admin/profile', [$controller, 'update']);
        };
        $session->start();
        if ($authenticated) {
            $_SESSION['admin_id'] = 'admin-1';
        }
        if ($csrfValid) {
            $_SESSION['csrf_token'] = 'valid-token';
            $_SESSION['csrf_issued_at'] = time();
        }
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
        if ($jsonContentType) {
            $server['CONTENT_TYPE'] = 'application/json; charset=utf-8';
        }
        if ($csrfValid) {
            $server['HTTP_X_CSRF_TOKEN'] = 'valid-token';
        }

        return (new Application($config, $logger, $routes, [new RequestIdMiddleware(), new CorsMiddleware([])]))->handle($server);
    }

    /**
     * @param array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string}|null $profile
     */
    private function pdo(?array &$profile, bool $databaseFailure): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$profile, $databaseFailure): PDOStatement {
            if ($databaseFailure && str_contains($sql, 'business_profiles')) {
                throw new PDOException('SQL connection details must stay private.');
            }
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(function (?array $parameters = null) use (&$profile, $sql): bool {
                if (str_starts_with($sql, 'UPDATE business_profiles') && $profile !== null) {
                    foreach ($parameters ?? [] as $field => $value) {
                        if (is_string($field) && array_key_exists($field, $profile)) {
                            $profile[$field] = is_bool($value) ? (int) $value : $value;
                        }
                    }
                    $profile['updated_at'] = '2026-08-13 12:00:00';
                }

                return true;
            });
            if (str_contains($sql, 'FROM business_profiles')) {
                $statement->method('fetch')->willReturnCallback(static fn (): array|false => $profile ?? false);
            } elseif (str_contains($sql, 'FROM merchant_users')) {
                $statement->method('fetch')->willReturn(['id' => 'admin-1', 'name' => 'Owner', 'email' => 'owner@example.com']);
            }

            return $statement;
        });

        return $pdo;
    }

    /** @return array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string} */
    private function profile(): array
    {
        return [
            'id' => 'profile-1',
            'business_name' => 'Demo Store',
            'slug' => 'demo-store',
            'domain' => 'demo.example.com',
            'whatsapp_number' => '+2348035732952',
            'support_email' => 'owner@example.com',
            'order_notification_email' => 'orders@example.com',
            'merchant_email_notifications_enabled' => 1,
            'customer_email_notifications_enabled' => 0,
            'whatsapp_handoff_enabled' => 1,
            'logo_url' => 'https://cdn.example.com/logo.png',
            'template_id' => 'classic',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'delivery_enabled' => 1,
            'pickup_enabled' => 1,
            'fixed_delivery_fee_kobo' => 150000,
            'created_at' => '2026-08-13 10:00:00',
            'updated_at' => '2026-08-13 10:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        return [
            'business_name' => 'Updated Store',
            'whatsapp_number' => '+2348035732952',
            'support_email' => 'support@example.com',
            'logo_url' => null,
            'template_id' => 'modern',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'delivery_enabled' => true,
            'pickup_enabled' => true,
            'fixed_delivery_fee_kobo' => 150000,
            'order_notification_email' => 'orders@example.com',
            'merchant_email_notifications_enabled' => true,
            'customer_email_notifications_enabled' => false,
            'whatsapp_handoff_enabled' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function responseProfile(\ProjectSync\Infrastructure\HttpResponse $response): array
    {
        $data = $response->body['data'] ?? null;
        if (!is_array($data)) {
            self::fail('Response data must be an object.');
        }
        return $this->stringObject($data['profile'] ?? null, 'profile');
    }

    /** @return array<string, mixed> */
    private function responseError(\ProjectSync\Infrastructure\HttpResponse $response): array
    {
        return $this->stringObject($response->body['error'] ?? null, 'error');
    }

    /** @return array<string, mixed> */
    private function stringObject(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            self::fail('Response ' . $label . ' must be an object.');
        }
        $object = [];
        foreach ($value as $field => $fieldValue) {
            if (!is_string($field)) {
                self::fail('Response ' . $label . ' must have string fields.');
            }
            $object[$field] = $fieldValue;
        }

        return $object;
    }
}
