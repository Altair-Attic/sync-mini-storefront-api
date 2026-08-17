<?php

declare(strict_types=1);

namespace Tests\Integration;

use Firebase\JWT\JWT;
use FastRoute\RouteCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\Admin\AuthController;
use ProjectSync\Controllers\Admin\CurrentAdminController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\AppFactory;
use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\Auth\RefreshCookie;
use ProjectSync\Infrastructure\Auth\SameOriginPolicy;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use ProjectSync\Repositories\AdminRefreshTokenRepository;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Repositories\RevokedAccessTokenRepository;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Services\LoginRateLimiter;
use ProjectSync\Validators\LoginValidator;
use Psr\Log\AbstractLogger;

final class AuthenticationApiTest extends TestCase
{
    private const ORIGIN = 'https://business.test';
    private const JWT_SECRET = 'authentication-jwt-test-secret-32-bytes';
    private const REFRESH_SECRET = 'authentication-refresh-test-secret-32-bytes';
    private const PASSWORD = 'Correct-horse-battery-staple-123!';

    private PDO $db;
    private string $administratorId;
    private JwtService $jwt;
    private AuthenticationTestLogger $logger;
    private string $email;

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
        $this->email = 'jwt-auth-' . bin2hex(random_bytes(8)) . '@example.com';
        $this->administratorId = UuidGenerator::v4();
        (new MerchantUserRepository($this->db))->create($this->administratorId, 'JWT Test Owner', $this->email, password_hash(self::PASSWORD, PASSWORD_DEFAULT));
        $this->jwt = new JwtService(self::JWT_SECRET, self::ORIGIN, self::ORIGIN . '/admin', 900, 30, 'HS256');
        $this->logger = new AuthenticationTestLogger();
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM merchant_users WHERE id = :id')->execute(['id' => $this->administratorId]);
    }

    public function testLoginReturnsAccessTokenAndSecureHostOnlyRefreshCookie(): void
    {
        $response = $this->login();
        self::assertSame(200, $response->status);
        $data = $this->data($response);
        self::assertSame('Bearer', $data['token_type'] ?? null);
        self::assertSame(900, $data['expires_in'] ?? null);
        self::assertIsString($data['access_token'] ?? null);
        self::assertArrayNotHasKey('refresh_token', $data);
        self::assertArrayNotHasKey('password_hash', $this->object($data['administrator'] ?? null));

        $cookie = $response->headers['Set-Cookie'] ?? '';
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Path=/api/v1/admin', $cookie);
        self::assertStringNotContainsString('Domain=', $cookie);
        $rawToken = $this->cookieValue($cookie);
        $statement = $this->db->prepare('SELECT token_hash FROM admin_refresh_tokens WHERE merchant_user_id = :merchant_user_id ORDER BY created_at DESC LIMIT 1');
        $statement->execute(['merchant_user_id' => $this->administratorId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $storedHash = $row['token_hash'] ?? null;
        self::assertIsString($storedHash);
        self::assertSame(64, strlen($storedHash));
        self::assertNotSame($rawToken, $storedHash);

        $logs = json_encode($this->logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string) $data['access_token'], $logs);
        self::assertStringNotContainsString($rawToken, $logs);
    }

    public function testLoginFailuresAreGenericAndRateLimited(): void
    {
        foreach ([
            ['email' => $this->email, 'password' => 'wrong-password'],
            ['email' => 'unknown-jwt-test@example.com', 'password' => 'wrong-password'],
        ] as $credentials) {
            $response = $this->request('POST', '/api/v1/admin/login', $credentials, ['REMOTE_ADDR' => UuidGenerator::v4()]);
            self::assertSame(401, $response->status);
            self::assertSame('INVALID_CREDENTIALS', $this->errorCode($response));
        }

        $this->db->prepare("UPDATE merchant_users SET status = 'inactive' WHERE id = :id")->execute(['id' => $this->administratorId]);
        $inactive = $this->request('POST', '/api/v1/admin/login', ['email' => $this->email, 'password' => self::PASSWORD], ['REMOTE_ADDR' => 'inactive-test']);
        self::assertSame(401, $inactive->status);
        self::assertSame('INVALID_CREDENTIALS', $this->errorCode($inactive));
        $this->db->prepare("UPDATE merchant_users SET status = 'active' WHERE id = :id")->execute(['id' => $this->administratorId]);

        $credentials = ['email' => 'rate-limited-jwt-test@example.com', 'password' => 'wrong'];
        $this->request('POST', '/api/v1/admin/login', $credentials, ['REMOTE_ADDR' => 'rate-test'], 2);
        $this->request('POST', '/api/v1/admin/login', $credentials, ['REMOTE_ADDR' => 'rate-test'], 2);
        $limited = $this->request('POST', '/api/v1/admin/login', $credentials, ['REMOTE_ADDR' => 'rate-test'], 2);
        self::assertSame(429, $limited->status);
        self::assertSame('RATE_LIMITED', $this->errorCode($limited));
    }

    public function testBearerValidationRejectsMalformedAndInvalidTokens(): void
    {
        $claims = $this->claims($this->administratorId);
        $cases = [
            null,
            'Basic abc',
            'Bearer token with spaces',
            'Bearer token.token.token, Bearer token.token.token',
            'Bearer ' . JWT::encode($claims, 'different-signing-secret-32-bytes', 'HS256'),
            'Bearer ' . JWT::encode($claims, str_repeat(self::JWT_SECRET, 2), 'HS512'),
            'Bearer ' . JWT::encode($this->claims($this->administratorId, ['exp' => time() - 60]), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode($this->claims($this->administratorId, ['nbf' => time() + 300]), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode($this->claims($this->administratorId, ['iss' => 'https://wrong.test']), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode($this->claims($this->administratorId, ['aud' => 'https://wrong.test/admin']), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode(array_diff_key($claims, ['jti' => true]), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode($this->claims($this->administratorId, ['token_version' => '0']), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode($this->claims($this->administratorId, ['iat' => (string) time()]), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode($this->claims($this->administratorId, ['email' => $this->email]), self::JWT_SECRET, 'HS256'),
            'Bearer ' . JWT::encode($this->claims('missing-administrator'), self::JWT_SECRET, 'HS256'),
        ];
        foreach ($cases as $authorization) {
            $server = $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization];
            $response = $this->request('GET', '/api/v1/admin/me', null, $server);
            self::assertSame(401, $response->status);
            self::assertSame('UNAUTHENTICATED', $this->errorCode($response));
        }

        $valid = $this->request('GET', '/api/v1/admin/me', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->issue($this->administratorId)['access_token']]);
        self::assertSame(200, $valid->status);
        self::assertArrayNotHasKey('password_hash', $this->object($this->data($valid)['administrator'] ?? null));

        $token = $this->jwt->issue($this->administratorId)['access_token'];
        $this->db->prepare("UPDATE merchant_users SET status = 'inactive' WHERE id = :id")->execute(['id' => $this->administratorId]);
        $inactive = $this->request('GET', '/api/v1/admin/me', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(401, $inactive->status);
    }

    public function testRefreshRotationReuseDetectionAndLogoutLifecycle(): void
    {
        $first = $this->login();
        $rawFirst = $this->cookieValue((string) ($first->headers['Set-Cookie'] ?? ''));
        $refresh = $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $rawFirst]);
        self::assertSame(200, $refresh->status);
        self::assertArrayNotHasKey('refresh_token', $this->data($refresh));
        $rawSecond = $this->cookieValue((string) ($refresh->headers['Set-Cookie'] ?? ''));
        self::assertNotSame($rawFirst, $rawSecond);

        $old = $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $rawFirst]);
        self::assertSame(401, $old->status);
        $familyRevoked = $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $rawSecond]);
        self::assertSame(401, $familyRevoked->status);

        $newLogin = $this->login();
        $logoutToken = $this->cookieValue((string) ($newLogin->headers['Set-Cookie'] ?? ''));
        $accessToken = $this->data($newLogin)['access_token'];
        self::assertIsString($accessToken);
        $logout = $this->request('POST', '/api/v1/admin/logout', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $logoutToken, 'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken]);
        self::assertSame(200, $logout->status);
        self::assertStringContainsString('Max-Age=0', (string) ($logout->headers['Set-Cookie'] ?? ''));
        self::assertSame(401, $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $logoutToken])->status);
        self::assertSame(401, $this->request('GET', '/api/v1/admin/me', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken])->status);
        self::assertSame(200, $this->request('POST', '/api/v1/admin/logout')->status);
        self::assertSame(200, $this->request('POST', '/api/v1/admin/logout', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=invalid'])->status);
    }

    public function testExpiredAndRevokedRefreshTokensAreRejected(): void
    {
        $expired = $this->cookieValue((string) ($this->login()->headers['Set-Cookie'] ?? ''));
        $this->db->prepare('UPDATE admin_refresh_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE token_hash = :hash')->execute(['hash' => hash_hmac('sha256', $expired, self::REFRESH_SECRET)]);
        self::assertSame(401, $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $expired])->status);

        $revoked = $this->cookieValue((string) ($this->login()->headers['Set-Cookie'] ?? ''));
        $this->db->prepare('UPDATE admin_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :hash')->execute(['hash' => hash_hmac('sha256', $revoked, self::REFRESH_SECRET)]);
        self::assertSame(401, $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $revoked])->status);
    }

    public function testOriginPolicyProtectedRoutesAndNoRegistrationEndpoint(): void
    {
        $wrongOrigin = $this->request('POST', '/api/v1/admin/login', ['email' => $this->email, 'password' => self::PASSWORD], ['HTTP_ORIGIN' => 'https://attacker.test']);
        self::assertSame(403, $wrongOrigin->status);
        self::assertSame(403, $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_ORIGIN' => 'https://attacker.test'])->status);
        self::assertSame(403, $this->request('POST', '/api/v1/admin/logout', null, ['HTTP_ORIGIN' => 'https://attacker.test'])->status);
        self::assertSame(404, $this->request('POST', '/api/v1/admin/register')->status);
        self::assertSame(404, $this->request('POST', '/api/v1/customer/login')->status);
    }

    public function testHeaderlessApiClientsCanUseTheAuthenticationLifecycle(): void
    {
        $login = $this->request('POST', '/api/v1/admin/login', ['email' => $this->email, 'password' => self::PASSWORD], includeOrigin: false);
        self::assertSame(200, $login->status);

        $refreshToken = $this->cookieValue((string) ($login->headers['Set-Cookie'] ?? ''));
        $refresh = $this->request('POST', '/api/v1/admin/refresh', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $refreshToken], includeOrigin: false);
        self::assertSame(200, $refresh->status);

        $replacementToken = $this->cookieValue((string) ($refresh->headers['Set-Cookie'] ?? ''));
        $logout = $this->request('POST', '/api/v1/admin/logout', null, ['HTTP_COOKIE' => 'project_sync_refresh_test=' . $replacementToken], includeOrigin: false);
        self::assertSame(200, $logout->status);
    }

    public function testEveryResourceAdminRouteRequiresBearerWhilePublicRoutesRemainPublic(): void
    {
        $application = AppFactory::create(dirname(__DIR__, 2));
        foreach ([
            ['GET', '/api/v1/admin/profile'],
            ['PUT', '/api/v1/admin/profile'],
            ['GET', '/api/v1/admin/categories'],
            ['POST', '/api/v1/admin/categories'],
            ['GET', '/api/v1/admin/categories/test-id'],
            ['PUT', '/api/v1/admin/categories/test-id'],
            ['DELETE', '/api/v1/admin/categories/test-id'],
            ['GET', '/api/v1/admin/products'],
            ['POST', '/api/v1/admin/products'],
            ['GET', '/api/v1/admin/products/test-id'],
            ['PUT', '/api/v1/admin/products/test-id'],
            ['DELETE', '/api/v1/admin/products/test-id'],
            ['POST', '/api/v1/admin/products/test-id/image'],
        ] as [$method, $uri]) {
            $response = $application->handle(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri]);
            self::assertSame(401, $response->status, $method . ' ' . $uri);
            self::assertSame('UNAUTHENTICATED', $this->errorCode($response));
        }

        foreach ([
            ['GET', '/api/v1/store'],
            ['GET', '/api/v1/categories'],
            ['GET', '/api/v1/products'],
            ['POST', '/api/v1/orders'],
        ] as [$method, $uri]) {
            $response = $application->handle(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri]);
            self::assertNotSame(401, $response->status, $method . ' ' . $uri);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $extraServer
     */
    private function request(string $method, string $uri, ?array $body = null, array $extraServer = [], int $maxAttempts = 5, bool $includeOrigin = true): HttpResponse
    {
        $config = new Config([
            'app.environment' => 'testing',
            'cors.allowed_origins' => [],
            'login.max_attempts' => (string) $maxAttempts,
            'login.window_seconds' => '900',
            'login.block_seconds' => '900',
            'login.rate_limit_secret' => 'authentication-rate-limit-test-secret',
        ]);
        $users = new MerchantUserRepository($this->db);
        $revokedTokens = new RevokedAccessTokenRepository($this->db);
        $cookie = new RefreshCookie('project_sync_refresh_test', '/api/v1/admin', true, 'Strict', 2592000);
        $service = new AuthenticationService(
            $users,
            new AdminRefreshTokenRepository($this->db),
            $revokedTokens,
            new LoginRateLimiter(new LoginAttemptRepository($this->db), $config),
            $this->jwt,
            self::REFRESH_SECRET,
            2592000,
            $this->logger,
        );
        $encoded = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $middleware = new AuthenticationMiddleware($this->jwt, $users, $revokedTokens);
        $auth = new AuthController($service, new LoginValidator(), $cookie, new SameOriginPolicy(self::ORIGIN), $middleware, static fn (): string => $encoded);
        $current = new CurrentAdminController($middleware);
        $routes = static function (RouteCollector $router) use ($auth, $current): void {
            $router->addRoute('POST', '/api/v1/admin/login', [$auth, 'login']);
            $router->addRoute('POST', '/api/v1/admin/refresh', [$auth, 'refresh']);
            $router->addRoute('GET', '/api/v1/admin/me', [$current, 'me']);
            $router->addRoute('POST', '/api/v1/admin/logout', [$auth, 'logout']);
        };
        $defaults = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'CONTENT_TYPE' => 'application/json'];
        if ($includeOrigin) {
            $defaults['HTTP_ORIGIN'] = self::ORIGIN;
        }
        $server = $extraServer + $defaults;

        return (new Application($config, $this->logger, $routes, [new RequestIdMiddleware(), new CorsMiddleware([])]))->handle($server);
    }

    private function login(): HttpResponse
    {
        return $this->request('POST', '/api/v1/admin/login', ['email' => $this->email, 'password' => self::PASSWORD], ['REMOTE_ADDR' => UuidGenerator::v4()]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(string $subject, array $overrides = []): array
    {
        $now = time();

        return $overrides + ['iss' => self::ORIGIN, 'aud' => self::ORIGIN . '/admin', 'sub' => $subject, 'iat' => $now, 'nbf' => $now, 'exp' => $now + 900, 'jti' => UuidGenerator::v4(), 'token_version' => 0];
    }

    /** @return array<string, mixed> */
    private function data(HttpResponse $response): array
    {
        return $this->object($response->body['data'] ?? null);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        return $value;
    }

    private function errorCode(HttpResponse $response): string
    {
        $error = $this->object($response->body['error'] ?? null);
        self::assertIsString($error['code'] ?? null);

        return $error['code'];
    }

    private function cookieValue(string $header): string
    {
        $pair = explode(';', $header, 2)[0];
        $value = explode('=', $pair, 2)[1] ?? '';
        self::assertNotSame('', $value);

        return rawurldecode($value);
    }
}

final class AuthenticationTestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
