<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class OpenApiSpecificationTest extends TestCase
{
    private string $root;
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        
        $jsonPath = $this->root . '/docs/openapi.json';
        $this->assertFileExists($jsonPath);
        
        $content = (string) file_get_contents($jsonPath);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        $this->spec = $decoded;
    }

    public function testCanonicalSpecMetadata(): void
    {
        $this->assertSame('3.0.3', $this->spec['openapi'] ?? null);
        /** @var array<string, mixed> $info */
        $info = $this->spec['info'] ?? [];
        $this->assertSame('Project Sync API', $info['title'] ?? null);
        $this->assertSame('1.0.0', $info['version'] ?? null);
        
        /** @var list<array<string, mixed>> $servers */
        $servers = $this->spec['servers'] ?? [];
        $this->assertNotEmpty($servers);
        $this->assertSame('/api/v1', $servers[0]['url'] ?? null);
    }

    public function testSecuritySchemesDefined(): void
    {
        /** @var array<string, mixed> $components */
        $components = $this->spec['components'] ?? [];
        /** @var array<string, array<string, mixed>> $securitySchemes */
        $securitySchemes = $components['securitySchemes'] ?? [];
        $this->assertArrayHasKey('bearerAuth', $securitySchemes);
        $this->assertSame('http', $securitySchemes['bearerAuth']['type'] ?? null);
        $this->assertSame('bearer', $securitySchemes['bearerAuth']['scheme'] ?? null);
        $this->assertSame('JWT', $securitySchemes['bearerAuth']['bearerFormat'] ?? null);

        $this->assertArrayHasKey('refreshCookie', $securitySchemes);
        $this->assertSame('apiKey', $securitySchemes['refreshCookie']['type'] ?? null);
        $this->assertSame('cookie', $securitySchemes['refreshCookie']['in'] ?? null);
        $this->assertSame('project_sync_refresh', $securitySchemes['refreshCookie']['name'] ?? null);
    }

    public function testParametersDefined(): void
    {
        /** @var array<string, mixed> $components */
        $components = $this->spec['components'] ?? [];
        /** @var array<string, array<string, mixed>> $parameters */
        $parameters = $components['parameters'] ?? [];
        $this->assertArrayHasKey('IdempotencyKey', $parameters);
        $this->assertSame('Idempotency-Key', $parameters['IdempotencyKey']['name'] ?? null);
        $this->assertSame('header', $parameters['IdempotencyKey']['in'] ?? null);
        $this->assertTrue((bool) ($parameters['IdempotencyKey']['required'] ?? false));

        $this->assertArrayHasKey('ConfirmationTokenHeader', $parameters);
        $this->assertSame('X-Confirmation-Token', $parameters['ConfirmationTokenHeader']['name'] ?? null);
        $this->assertSame('header', $parameters['ConfirmationTokenHeader']['in'] ?? null);
    }

    public function testMoneySchema(): void
    {
        /** @var array<string, mixed> $components */
        $components = $this->spec['components'] ?? [];
        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'] ?? [];
        /** @var array<string, mixed> $money */
        $money = $schemas['MoneyKobo'] ?? [];
        $this->assertSame('integer', $money['type'] ?? null);
        $this->assertSame(0, $money['minimum'] ?? null);
    }

    public function testFulfilmentStatusEnumDoesNotContainPending(): void
    {
        /** @var array<string, mixed> $components */
        $components = $this->spec['components'] ?? [];
        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'] ?? [];
        /** @var array<string, mixed> $orderRecord */
        $orderRecord = $schemas['OrderRecord'] ?? [];
        /** @var array<string, mixed> $properties */
        $properties = $orderRecord['properties'] ?? [];
        /** @var array<string, mixed> $fulfilmentStatus */
        $fulfilmentStatus = $properties['fulfilment_status'] ?? [];
        $enum = $fulfilmentStatus['enum'] ?? [];

        $this->assertSame(['new', 'confirmed', 'processing', 'ready', 'completed', 'cancelled'], $enum);
        $this->assertNotContains('pending', $enum);
    }

    public function testPaymentAttemptAndAggregatePaymentStatuses(): void
    {
        /** @var array<string, mixed> $components */
        $components = $this->spec['components'] ?? [];
        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'] ?? [];
        /** @var array<string, mixed> $paymentStatusResponse */
        $paymentStatusResponse = $schemas['PaymentStatusResponse'] ?? [];
        /** @var array<string, mixed> $responseProps */
        $responseProps = $paymentStatusResponse['properties'] ?? [];
        /** @var array<string, mixed> $dataProp */
        $dataProp = $responseProps['data'] ?? [];
        /** @var array<string, mixed> $dataProperties */
        $dataProperties = $dataProp['properties'] ?? [];
        
        /** @var array<string, mixed> $statusProp */
        $statusProp = $dataProperties['status'] ?? [];
        $attemptEnum = $statusProp['enum'] ?? [];
        $this->assertSame(['initialized', 'pending', 'successful', 'failed', 'abandoned'], $attemptEnum);

        /** @var array<string, mixed> $paymentStatusProp */
        $paymentStatusProp = $dataProperties['payment_status'] ?? [];
        $orderPaymentEnum = $paymentStatusProp['enum'] ?? [];
        $this->assertSame(['unpaid', 'pending', 'paid', 'refunded'], $orderPaymentEnum);

        /** @var array<string, mixed> $resolutionProp */
        $resolutionProp = $dataProperties['resolution_status'] ?? [];
        $resolutionEnum = $resolutionProp['enum'] ?? [];
        $this->assertSame(['none', 'requires_action'], $resolutionEnum);
    }

    public function testAllImplementedApiRoutesAreDocumentedInSpec(): void
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $this->spec['paths'] ?? [];

        $expectedEndpoints = [
            '/health' => ['get'],
            '/store' => ['get'],
            '/categories' => ['get'],
            '/products' => ['get'],
            '/products/{slug}' => ['get'],
            '/orders' => ['post'],
            '/orders/{reference}/confirmation' => ['get'],
            '/orders/{reference}/payments' => ['post'],
            '/orders/{reference}/payments/{paymentReference}' => ['get'],
            '/payments/paystack/webhook' => ['post'],
            '/admin/login' => ['post'],
            '/admin/refresh' => ['post'],
            '/admin/me' => ['get'],
            '/admin/logout' => ['post'],
            '/admin/profile' => ['get', 'put'],
            '/admin/categories' => ['get', 'post'],
            '/admin/categories/{id}' => ['get', 'put', 'delete'],
            '/admin/products' => ['get', 'post'],
            '/admin/products/{id}' => ['get', 'put', 'delete'],
            '/admin/products/{id}/availability' => ['patch'],
            '/admin/products/{id}/image' => ['post'],
            '/admin/orders' => ['get'],
            '/admin/orders/summary' => ['get'],
            '/admin/orders/{id}' => ['get'],
            '/admin/orders/{id}/status' => ['patch'],
            '/admin/orders/{orderId}/payments' => ['get'],
            '/admin/orders/{orderId}/payments/{paymentId}/reconcile' => ['post'],
        ];

        foreach ($expectedEndpoints as $path => $methods) {
            $this->assertArrayHasKey($path, $paths, "Missing documented path in OpenAPI spec: {$path}");
            $pathItem = $paths[$path];
            foreach ($methods as $method) {
                $this->assertArrayHasKey($method, $pathItem, "Missing HTTP method {$method} for path {$path}");
            }
        }
    }

    public function testAllInternalSchemaRefsResolve(): void
    {
        $this->resolveRefsRecursively($this->spec, $this->spec);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $root
     */
    private function resolveRefsRecursively(array $current, array $root): void
    {
        foreach ($current as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $this->assertStringStartsWith('#/', $value, "External reference found: {$value}");
                $parts = explode('/', ltrim($value, '#/'));
                /** @var array<string, mixed> $pointer */
                $pointer = $root;
                foreach ($parts as $part) {
                    $this->assertArrayHasKey($part, $pointer, "Unresolved \$ref target: {$value}");
                    /** @var array<string, mixed> $pointer */
                    $pointer = is_array($pointer[$part]) ? $pointer[$part] : [];
                }
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $this->resolveRefsRecursively($value, $root);
            }
        }
    }
}
