<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\AppFactory;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\ApplicationBootstrap;

final class DocumentationEndpointTest extends TestCase
{
    private Application $app;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        ApplicationBootstrap::loadEnvironment($this->root);
        $this->app = AppFactory::create($this->root);
    }

    public function testSwaggerUiRouteReturns200Html(): void
    {
        $response = $this->app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/docs',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('text/html', $response->headers['Content-Type'] ?? '');
        $this->assertIsString($response->rawBody);
        $this->assertStringContainsString('Project Sync API', $response->rawBody);
        $this->assertStringContainsString('SwaggerUIBundle', $response->rawBody);
        $this->assertStringContainsString('/api/openapi.yaml', $response->rawBody);
    }

    public function testVersionedSwaggerUiRouteReturns200Html(): void
    {
        $response = $this->app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/docs',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('text/html', $response->headers['Content-Type'] ?? '');
        $this->assertIsString($response->rawBody);
        $this->assertStringContainsString('Project Sync API', $response->rawBody);
    }

    public function testOpenApiYamlRouteReturns200Yaml(): void
    {
        $response = $this->app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/openapi.yaml',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('application/yaml', $response->headers['Content-Type'] ?? '');
        $this->assertIsString($response->rawBody);
        $this->assertStringContainsString('openapi: 3.0.3', $response->rawBody);
        $this->assertStringContainsString('title: Project Sync API', $response->rawBody);
        $this->assertStringContainsString('version: 1.0.0', $response->rawBody);
    }

    public function testVersionedOpenApiYamlRouteReturns200Yaml(): void
    {
        $response = $this->app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/openapi.yaml',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('application/yaml', $response->headers['Content-Type'] ?? '');
        $this->assertIsString($response->rawBody);
        $this->assertStringContainsString('title: Project Sync API', $response->rawBody);
    }

    public function testOpenApiJsonRouteReturns200Json(): void
    {
        $response = $this->app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/openapi.json',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('application/json', $response->headers['Content-Type'] ?? '');
        $this->assertIsString($response->rawBody);
        
        $decoded = json_decode($response->rawBody, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        $info = $decoded['info'] ?? null;
        $this->assertIsArray($info);
        $this->assertSame('Project Sync API', $info['title'] ?? null);
        $this->assertSame('1.0.0', $info['version'] ?? null);
    }

    public function testVersionedOpenApiJsonRouteReturns200Json(): void
    {
        $response = $this->app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/openapi.json',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('application/json', $response->headers['Content-Type'] ?? '');
        $this->assertIsString($response->rawBody);
        
        $decoded = json_decode($response->rawBody, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        $info = $decoded['info'] ?? null;
        $this->assertIsArray($info);
        $this->assertSame('Project Sync API', $info['title'] ?? null);
    }

    public function testDocumentationDoesNotExposeApplicationSecrets(): void
    {
        $yamlResponse = $this->app->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/openapi.yaml',
        ]);

        $rawBody = (string) $yamlResponse->rawBody;
        
        // Assert actual configured secret values do not appear in output
        $paystackSecret = $_ENV['PAYSTACK_SECRET_KEY'] ?? null;
        if (is_string($paystackSecret) && $paystackSecret !== '') {
            $this->assertStringNotContainsString($paystackSecret, $rawBody);
        }
        $jwtSecret = $_ENV['JWT_SECRET'] ?? null;
        if (is_string($jwtSecret) && $jwtSecret !== '') {
            $this->assertStringNotContainsString($jwtSecret, $rawBody);
        }
        $dbPassword = $_ENV['DB_PASSWORD'] ?? null;
        if (is_string($dbPassword) && $dbPassword !== '') {
            $this->assertStringNotContainsString($dbPassword, $rawBody);
        }
        $smtpPassword = $_ENV['SMTP_PASSWORD'] ?? null;
        if (is_string($smtpPassword) && $smtpPassword !== '') {
            $this->assertStringNotContainsString($smtpPassword, $rawBody);
        }
    }
}
