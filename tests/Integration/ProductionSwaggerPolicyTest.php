<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\DocumentationController;
use ProjectSync\Infrastructure\HttpResponse;

final class ProductionSwaggerPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testSwaggerDisabledReturns404ForAllDocumentationRoutes(): void
    {
        $controller = new DocumentationController($this->root, enabled: false);
        $requestId = 'req_test_docs_disabled';
        $server = ['REQUEST_METHOD' => 'GET'];
        $params = [];

        $uiResponse = $controller->ui($requestId, $server, $params);
        $this->assertSame(404, $uiResponse->status);
        /** @var array<string, mixed> $uiError */
        $uiError = $uiResponse->body['error'] ?? [];
        $this->assertSame('NOT_FOUND', $uiError['code'] ?? null);

        $yamlResponse = $controller->yaml($requestId, $server, $params);
        $this->assertSame(404, $yamlResponse->status);
        /** @var array<string, mixed> $yamlError */
        $yamlError = $yamlResponse->body['error'] ?? [];
        $this->assertSame('NOT_FOUND', $yamlError['code'] ?? null);

        $jsonResponse = $controller->json($requestId, $server, $params);
        $this->assertSame(404, $jsonResponse->status);
        /** @var array<string, mixed> $jsonError */
        $jsonError = $jsonResponse->body['error'] ?? [];
        $this->assertSame('NOT_FOUND', $jsonError['code'] ?? null);
    }

    public function testSwaggerEnabledReturns200ForAllDocumentationRoutes(): void
    {
        $controller = new DocumentationController($this->root, enabled: true);
        $requestId = 'req_test_docs_enabled';
        $server = ['REQUEST_METHOD' => 'GET'];
        $params = [];

        $uiResponse = $controller->ui($requestId, $server, $params);
        $this->assertSame(200, $uiResponse->status);
        $this->assertStringContainsString('text/html', $uiResponse->headers['Content-Type'] ?? '');

        $yamlResponse = $controller->yaml($requestId, $server, $params);
        $this->assertSame(200, $yamlResponse->status);
        $this->assertStringContainsString('openapi: 3.0.3', (string) $yamlResponse->rawBody);

        $jsonResponse = $controller->json($requestId, $server, $params);
        $this->assertSame(200, $jsonResponse->status);
        $this->assertStringContainsString('Project Sync API', (string) $jsonResponse->rawBody);
    }
}
