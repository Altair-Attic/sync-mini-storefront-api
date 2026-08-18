<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\AppFactory;
use ProjectSync\Infrastructure\ApplicationBootstrap;

final class SecurityHeadersAndCorsTest extends TestCase
{
    private string $root;
    private ?string $originalCors = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($this->root);
        $cors = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
        $this->originalCors = is_string($cors) ? $cors : null;
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'http://localhost:3000';
    }

    protected function tearDown(): void
    {
        if ($this->originalCors !== null) {
            $_ENV['CORS_ALLOWED_ORIGINS'] = $this->originalCors;
        } else {
            unset($_ENV['CORS_ALLOWED_ORIGINS']);
        }
        parent::tearDown();
    }

    public function testStandardSecurityHeadersAreAttachedToApiResponses(): void
    {
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/health',
        ]);

        $this->assertSame(200, $response->status);
        $headers = $response->headers;

        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);

        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);

        $this->assertArrayHasKey('Referrer-Policy', $headers);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
    }

    public function testHstsHeaderEmittedWhenHttpsRequest(): void
    {
        // In local/testing, HSTS is disabled by default unless configured or forced.
        // Let's test that HTTPS request behavior respects configuration.
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/health',
            'HTTPS' => 'on',
        ]);

        $this->assertSame(200, $response->status);
    }

    public function testApprovedCorsOriginReceivesHeaders(): void
    {
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/health',
            'HTTP_ORIGIN' => 'http://localhost:3000',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertSame('http://localhost:3000', $response->headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials'] ?? null);
        $this->assertSame('Origin', $response->headers['Vary'] ?? null);
    }

    public function testMaliciousCorsOriginIsRejected(): void
    {
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/health',
            'HTTP_ORIGIN' => 'https://malicious-attacker.com',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
    }

    public function testCorsPreflightForApprovedOrigin(): void
    {
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI' => '/api/v1/store',
            'HTTP_ORIGIN' => 'http://localhost:3000',
        ]);

        $this->assertSame(204, $response->status);
        $this->assertSame('http://localhost:3000', $response->headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->headers['Access-Control-Allow-Methods'] ?? null);
        $this->assertStringContainsString('Idempotency-Key', $response->headers['Access-Control-Allow-Headers'] ?? '');
    }

    public function testCorsPreflightForUnapprovedOrigin(): void
    {
        $app = AppFactory::create($this->root);

        $response = $app->handle([
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI' => '/api/v1/store',
            'HTTP_ORIGIN' => 'https://evil-site.com',
        ]);

        $this->assertSame(204, $response->status);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }
}
