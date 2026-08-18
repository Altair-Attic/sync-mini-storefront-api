<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\HealthController;
use ProjectSync\Infrastructure\AppFactory;
use ProjectSync\Infrastructure\ApplicationBootstrap;

final class ReadinessEndpointTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($this->root);
    }

    public function testHealthEndpointReturnsMinimalPublicStatus(): void
    {
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/health',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertTrue((bool) ($response->body['success'] ?? false));
        /** @var array<string, mixed> $data */
        $data = $response->body['data'] ?? [];
        $this->assertSame('ok', $data['status'] ?? null);

        // Prove no internal secrets or hostnames leaked
        $this->assertArrayNotHasKey('database', $data);
        $this->assertArrayNotHasKey('db_host', $data);
        $this->assertArrayNotHasKey('environment', $data);
    }

    public function testReadinessEndpointReturnsReadyWhenDatabaseIsConnected(): void
    {
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/health/ready',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertTrue((bool) ($response->body['success'] ?? false));
        /** @var array<string, mixed> $data */
        $data = $response->body['data'] ?? [];
        $this->assertSame('ready', $data['status'] ?? null);
        $this->assertSame('connected', $data['database'] ?? null);
    }

    public function testReadinessEndpointReturns503WhenDatabaseFails(): void
    {
        // Mock a PDO that fails on query
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->willReturn(false);

        $controller = new HealthController($mockPdo);
        $response = $controller->readiness('req_test_readiness_fail');

        $this->assertSame(503, $response->status);
        $this->assertFalse((bool) ($response->body['success'] ?? true));
        /** @var array<string, mixed> $error */
        $error = $response->body['error'] ?? [];
        $this->assertSame('SERVICE_UNAVAILABLE', $error['code'] ?? null);
        $this->assertSame('Service dependency check failed.', $error['message'] ?? null);
    }
}
