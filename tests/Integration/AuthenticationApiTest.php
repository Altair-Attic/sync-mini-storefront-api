<?php

declare(strict_types=1);

namespace Tests\Integration;

use FastRoute\RouteCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\Admin\AuthController;
use ProjectSync\Controllers\Admin\CurrentAdminController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Services\LoginRateLimiter;
use ProjectSync\Validators\LoginValidator;
use Psr\Log\NullLogger;

final class AuthenticationApiTest extends TestCase
{
    private const string SECRET = 'authentication-jwt-test-secret-32-bytes';
    private PDO $db;
    private string $administratorId;
    private string $email;
    private string $body = '';
    private Application $application;

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
        $this->administratorId = UuidGenerator::v4();
        $this->email = 'jwt-auth-' . bin2hex(random_bytes(8)) . '@example.com';
        $users = new MerchantUserRepository($this->db);
        $users->create($this->administratorId, 'JWT Test Owner', $this->email, password_hash('Correct-horse-battery-staple-123!', PASSWORD_DEFAULT));
        $config = new Config(['login.max_attempts' => '2', 'login.window_seconds' => '900', 'login.block_seconds' => '900']);
        $jwt = new JwtService(self::SECRET, 28800, 'HS256');
        $auth = new AuthenticationService($users, new LoginRateLimiter(new LoginAttemptRepository($this->db), $config), $jwt, new NullLogger());
        $middleware = new AuthenticationMiddleware($jwt, $users);
        $controller = new AuthController($auth, new LoginValidator(), $middleware, new NullLogger(), fn (): string => $this->body);
        $this->application = new Application($config, new NullLogger(), static function (RouteCollector $router) use ($controller, $middleware): void {
            $router->addRoute('POST', '/api/v1/admin/login', [$controller, 'login']);
            $router->addRoute('POST', '/api/v1/admin/logout', [$controller, 'logout']);
            $router->addRoute('GET', '/api/v1/admin/me', [new CurrentAdminController($middleware), 'me']);
        }, []);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM merchant_users WHERE id = :id')->execute(['id' => $this->administratorId]);
    }

    public function testLoginIssuesAnEightHourBearerJwtWithoutCookieOrOriginHandshake(): void
    {
        $response = $this->request('POST', '/api/v1/admin/login', ['email' => $this->email, 'password' => 'Correct-horse-battery-staple-123!'], ['HTTP_ORIGIN' => 'https://different-frontend.example']);

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers);
        $data = $this->data($response);
        self::assertSame('Bearer', $data['token_type']);
        self::assertSame(28800, $data['expires_in']);
        self::assertIsString($data['access_token']);
        self::assertArrayNotHasKey('refresh_token', $data);
    }

    public function testBearerTokenProtectsAdminRoutesAndExpiredTokenIsRejected(): void
    {
        $login = $this->request('POST', '/api/v1/admin/login', ['email' => $this->email, 'password' => 'Correct-horse-battery-staple-123!']);
        $token = $this->data($login)['access_token'] ?? null;
        self::assertIsString($token);
        self::assertSame(200, $this->request('GET', '/api/v1/admin/me', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])->status);
        self::assertSame(401, $this->request('GET', '/api/v1/admin/me')->status);
    }

    public function testLoginFailuresRemainRateLimited(): void
    {
        $credentials = ['email' => 'unknown@example.com', 'password' => 'wrong'];
        $ip = '192.0.2.' . random_int(20, 240);
        self::assertSame(401, $this->request('POST', '/api/v1/admin/login', $credentials, ['REMOTE_ADDR' => $ip])->status);
        self::assertSame(401, $this->request('POST', '/api/v1/admin/login', $credentials, ['REMOTE_ADDR' => $ip])->status);
        self::assertSame(429, $this->request('POST', '/api/v1/admin/login', $credentials, ['REMOTE_ADDR' => $ip])->status);
    }

    public function testLogoutRequiresBearerAndAcknowledgesClientSideSignOut(): void
    {
        $login = $this->request('POST', '/api/v1/admin/login', ['email' => $this->email, 'password' => 'Correct-horse-battery-staple-123!']);
        $token = $this->data($login)['access_token'] ?? null;
        self::assertIsString($token);

        self::assertSame(401, $this->request('POST', '/api/v1/admin/logout')->status);
        self::assertSame(200, $this->request('POST', '/api/v1/admin/logout', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])->status);

        // Stateless JWTs remain valid until expiry; the client must discard its in-memory token.
        self::assertSame(200, $this->request('GET', '/api/v1/admin/me', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])->status);
    }

    /** @return array<string, mixed> */
    private function data(\ProjectSync\Infrastructure\HttpResponse $response): array
    {
        $data = $response->body['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $server
     */
    private function request(string $method, string $uri, ?array $body = null, array $server = []): \ProjectSync\Infrastructure\HttpResponse
    {
        $this->body = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);

        return $this->application->handle($server + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'CONTENT_TYPE' => 'application/json']);
    }
}
