<?php

declare(strict_types=1);

/**
 * Project Sync Production Smoke Test Script
 * 
 * Safe read-only HTTP validation tool for newly deployed backend environments.
 * Verifies core API availability, health, store profile, catalogue, security routing,
 * and Swagger policy without mutating state or triggering payment transactions.
 * 
 * Usage:
 *   php scripts/smoke-test.php
 *   php scripts/smoke-test.php --url=https://store.example.com
 *   php scripts/smoke-test.php --url=http://localhost:8000 --docs-expected=404
 * 
 * Exit Codes:
 *   0 = All smoke tests passed.
 *   1 = One or more smoke tests failed.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use ProjectSync\Infrastructure\ApplicationBootstrap;

final class ProductionSmokeTester
{
    private string $baseUrl;
    private ?int $docsExpectedStatus;
    private int $passes = 0;
    private int $failures = 0;

    public function __construct(string $baseUrl, ?int $docsExpectedStatus = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->docsExpectedStatus = $docsExpectedStatus;
    }

    public function run(): int
    {
        echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
        echo "  Project Sync Post-Deployment Smoke Test" . PHP_EOL;
        echo "  Target URL: " . $this->baseUrl . PHP_EOL;
        echo str_repeat('=', 70) . PHP_EOL . PHP_EOL;

        $this->testHealthEndpoint();
        $this->testReadinessEndpoint();
        $this->testStoreProfileEndpoint();
        $this->testCategoriesEndpoint();
        $this->testProductsEndpoint();
        $this->testSwaggerEndpoint();
        $this->testEnvFileProtection();

        echo PHP_EOL . str_repeat('-', 70) . PHP_EOL;
        if ($this->failures === 0) {
            echo sprintf("  SMOKE TEST RESULT: PASSED (%d passed, 0 failed)%s", $this->passes, PHP_EOL);
            echo "  Deployment verification succeeded." . PHP_EOL;
        } else {
            echo sprintf("  SMOKE TEST RESULT: FAILED (%d passed, %d failed)%s", $this->passes, $this->failures, PHP_EOL);
            echo "  Investigate the failures above immediately." . PHP_EOL;
        }
        echo str_repeat('-', 70) . PHP_EOL . PHP_EOL;

        return $this->failures > 0 ? 1 : 0;
    }

    private function testHealthEndpoint(): void
    {
        $url = $this->baseUrl . '/api/v1/health';
        $res = $this->get($url);

        if ($res['status'] === 200 && ($res['json']['data']['status'] ?? null) === 'ok') {
            $this->pass("Health check (GET /api/v1/health) returned 200 OK");
        } else {
            $this->fail(sprintf("Health check failed. Status: %d, Body: %s", $res['status'], substr($res['raw'], 0, 100)));
        }
    }

    private function testReadinessEndpoint(): void
    {
        $url = $this->baseUrl . '/api/v1/health/ready';
        $res = $this->get($url);

        if ($res['status'] === 200 && ($res['json']['data']['status'] ?? null) === 'ready') {
            $this->pass("Readiness check (GET /api/v1/health/ready) returned 200 OK with ready status");
        } else {
            $this->fail(sprintf("Readiness check failed. Status: %d, Body: %s", $res['status'], substr($res['raw'], 0, 100)));
        }
    }

    private function testStoreProfileEndpoint(): void
    {
        $url = $this->baseUrl . '/api/v1/store';
        $res = $this->get($url);

        $name = $res['json']['data']['profile']['business_name'] ?? ($res['json']['data']['business_name'] ?? null);
        if ($res['status'] === 200 && is_string($name) && $name !== '') {
            $this->pass(sprintf("Public store profile (GET /api/v1/store) returned 200 OK for '%s'", $name));
        } else {
            $this->fail(sprintf("Store profile endpoint failed. Status: %d, Body: %s", $res['status'], substr($res['raw'], 0, 100)));
        }
    }

    private function testCategoriesEndpoint(): void
    {
        $url = $this->baseUrl . '/api/v1/categories';
        $res = $this->get($url);

        if ($res['status'] === 200 && is_array($res['json']['data'] ?? null)) {
            $this->pass(sprintf("Public categories (GET /api/v1/categories) returned 200 OK (%d categories)", count($res['json']['data'])));
        } else {
            $this->fail(sprintf("Categories endpoint failed. Status: %d, Body: %s", $res['status'], substr($res['raw'], 0, 100)));
        }
    }

    private function testProductsEndpoint(): void
    {
        $url = $this->baseUrl . '/api/v1/products';
        $res = $this->get($url);

        if ($res['status'] === 200 && is_array($res['json']['data'] ?? null)) {
            $this->pass(sprintf("Public products (GET /api/v1/products) returned 200 OK (%d products on page 1)", count($res['json']['data'])));
        } else {
            $this->fail(sprintf("Products endpoint failed. Status: %d, Body: %s", $res['status'], substr($res['raw'], 0, 100)));
        }
    }

    private function testSwaggerEndpoint(): void
    {
        $url = $this->baseUrl . '/api/docs';
        $res = $this->get($url);

        if ($this->docsExpectedStatus !== null) {
            if ($res['status'] === $this->docsExpectedStatus) {
                $this->pass(sprintf("Swagger endpoint (GET /api/docs) returned expected HTTP %d", $this->docsExpectedStatus));
            } else {
                $this->fail(sprintf("Swagger endpoint returned HTTP %d, expected HTTP %d", $res['status'], $this->docsExpectedStatus));
            }
        } else {
            if ($res['status'] === 200 || $res['status'] === 404) {
                $this->pass(sprintf("Swagger endpoint (GET /api/docs) responded with valid policy status (HTTP %d)", $res['status']));
            } else {
                $this->fail(sprintf("Swagger endpoint returned unexpected status: %d", $res['status']));
            }
        }
    }

    private function testEnvFileProtection(): void
    {
        $url = $this->baseUrl . '/.env';
        $res = $this->get($url);

        if ($res['status'] === 403 || $res['status'] === 404) {
            $this->pass(sprintf(".env file access protection confirmed (HTTP %d returned)", $res['status']));
        } elseif (str_contains($res['raw'], 'APP_ENV') || str_contains($res['raw'], 'DB_PASSWORD')) {
            $this->fail("CRITICAL SECURITY RISK: .env file is directly accessible over HTTP!");
        } else {
            $this->pass(sprintf(".env file did not expose secrets (HTTP %d returned)", $res['status']));
        }
    }

    /**
     * @return array{status: int, headers: array<string, string>, raw: string, json: array<mixed>}
     */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json, text/html, */*',
            'User-Agent: ProjectSync-SmokeTester/1.0',
        ]);

        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var array<mixed> $json */
        $json = json_decode($raw, true) ?? [];

        return [
            'status' => $status,
            'headers' => [],
            'raw' => $raw,
            'json' => $json,
        ];
    }

    private function pass(string $message): void
    {
        $this->passes++;
        echo "  [PASS] " . $message . PHP_EOL;
    }

    private function fail(string $message): void
    {
        $this->failures++;
        echo "  [FAIL] " . $message . PHP_EOL;
    }
}

$root = dirname(__DIR__);
ApplicationBootstrap::loadEnvironment($root);
$app = is_file($root . '/config/app.php') ? require $root . '/config/app.php' : [];

$url = $app['url'] ?? 'http://localhost:8000';
$docsExpected = null;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    }
    if (str_starts_with($arg, '--docs-expected=')) {
        $docsExpected = (int) substr($arg, 16);
    }
}

$tester = new ProductionSmokeTester((string) $url, $docsExpected);
exit($tester->run());
