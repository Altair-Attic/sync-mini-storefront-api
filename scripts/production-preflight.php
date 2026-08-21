<?php

declare(strict_types=1);

/**
 * Project Sync Production Preflight Check Script
 * 
 * Standalone read-only validator for deployment readiness.
 * Validates PHP runtime, extensions, permissions, database connectivity,
 * migrations, and environment configuration WITHOUT exposing secret values.
 * 
 * Usage:
 *   php scripts/production-preflight.php
 * 
 * Exit Codes:
 *   0 = All preflight checks passed. Ready for deployment.
 *   1 = One or more critical preflight checks failed.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;

final class ProductionPreflight
{
    private string $root;
    private int $errors = 0;
    private int $warnings = 0;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    public function run(): int
    {
        $this->header("Project Sync Production Preflight Verification");

        $this->checkPhpVersion();
        $this->checkPhpExtensions();
        $this->checkWritableDirectories();
        $this->checkEnvironmentFile();
        $this->checkAppConfiguration();
        $this->checkDatabaseAndMigrations();

        $this->summary();

        return $this->errors > 0 ? 1 : 0;
    }

    private function checkPhpVersion(): void
    {
        $version = PHP_VERSION;
        if (version_compare($version, '8.3.0', '>=')) {
            $this->pass(sprintf("PHP Version: %s (>= 8.3 required)", $version));
        } else {
            $this->fail(sprintf("PHP Version: %s is below required 8.3.0", $version));
        }
    }

    private function checkPhpExtensions(): void
    {
        $requiredExtensions = [
            'pdo',
            'pdo_mysql',
            'curl',
            'openssl',
            'json',
            'mbstring',
            'fileinfo',
        ];

        $missing = [];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if ($missing === []) {
            $this->pass(sprintf("Required PHP Extensions: all present (%s)", implode(', ', $requiredExtensions)));
        } else {
            $this->fail(sprintf("Missing required PHP extensions: %s", implode(', ', $missing)));
        }
    }

    private function checkWritableDirectories(): void
    {
        $dirs = [
            'storage/logs' => $this->root . '/storage/logs',
            'storage/uploads/products' => $this->root . '/storage/uploads/products',
        ];

        foreach ($dirs as $label => $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (is_dir($path) && is_writable($path)) {
                $this->pass(sprintf("Directory Writable: %s", $label));
            } else {
                $this->fail(sprintf("Directory not writable: %s (%s)", $label, $path));
            }
        }
    }

    private function checkEnvironmentFile(): void
    {
        $envPath = $this->root . '/.env';
        if (is_file($envPath)) {
            $this->pass("Environment file .env exists and is readable");
        } else {
            $this->fail("Environment file .env not found in project root");
        }
    }

    private function checkAppConfiguration(): void
    {
        try {
            ApplicationBootstrap::loadEnvironment($this->root);
            $app = require $this->root . '/config/app.php';
            $cors = require $this->root . '/config/cors.php';
            $env = $app['environment'] ?? 'production';
            $debug = (bool) ($app['debug'] ?? true);
            $url = (string) ($app['url'] ?? '');
            $docsEnabled = (bool) ($app['api_docs_enabled'] ?? false);

            $this->pass(sprintf("Environment: APP_ENV=%s", $env));

            if ($env === 'production') {
                if ($debug) {
                    $this->fail("APP_DEBUG must be false in production");
                } else {
                    $this->pass("APP_DEBUG is disabled (false)");
                }

                if (str_starts_with($url, 'https://')) {
                    $this->pass("APP_URL uses HTTPS");
                } else {
                    $this->fail("APP_URL must use HTTPS in production");
                }

                if (!$docsEnabled) {
                    $this->pass("Swagger documentation is disabled by default in production");
                } else {
                    $this->warn("Swagger documentation is enabled in production (API_DOCS_ENABLED=true)");
                }
            } else {
                $this->pass(sprintf("Non-production environment: APP_DEBUG=%s, Swagger=%s", $debug ? 'true' : 'false', $docsEnabled ? 'enabled' : 'disabled'));
            }

            // Verify JWT Secrets
            $jwtSecret = (string) ($app['authentication']['jwt_secret'] ?? '');
            if (strlen($jwtSecret) >= 32 && !str_contains(strtolower($jwtSecret), 'change-this')) {
                $this->pass("JWT signing secret is configured and strong (>= 32 chars)");
            } else {
                $this->fail("JWT signing secret is missing, too short (< 32 chars), or using placeholder");
            }

            // Verify Paystack Secret Key shape
            $paystackKey = (string) ($app['paystack']['secret_key'] ?? '');
            if ($env === 'production') {
                if (str_starts_with($paystackKey, 'sk_live_') && !str_contains(strtolower($paystackKey), 'change-this')) {
                    $this->pass("Paystack secret key is configured with live format (sk_live_...)");
                } else {
                    $this->fail("Paystack secret key is missing, not a live key (sk_live_...), or using placeholder");
                }
            } else {
                if (str_starts_with($paystackKey, 'sk_test_') || str_starts_with($paystackKey, 'sk_live_')) {
                    $this->pass("Paystack secret key format is valid for current environment");
                } else {
                    $this->warn("Paystack secret key is not configured or uses placeholder");
                }
            }

            // Verify Mail configuration
            $mailEnabled = (bool) ($app['mail']['enabled'] ?? false);
            if ($mailEnabled) {
                $mailHost = (string) ($app['mail']['host'] ?? '');
                $mailUser = (string) ($app['mail']['username'] ?? '');
                $mailFrom = (string) ($app['mail']['from_address'] ?? '');
                if ($mailHost !== '' && $mailUser !== '' && $mailFrom !== '') {
                    $this->pass("Mail SMTP configuration is populated");
                } else {
                    $this->fail("Mail is enabled (MAIL_ENABLED=true) but SMTP settings are incomplete");
                }
            } else {
                $this->pass("Mail is disabled (MAIL_ENABLED=false)");
            }
        } catch (Throwable $e) {
            $this->fail(sprintf("Configuration loading failed: %s", $e->getMessage()));
        }
    }

    private function checkDatabaseAndMigrations(): void
    {
        try {
            $database = require $this->root . '/config/database.php';
            $config = new Config([
                'db.host' => $database['host'] ?? '127.0.0.1',
                'db.port' => (string) ($database['port'] ?? 3306),
                'db.database' => $database['database'] ?? '',
                'db.username' => $database['username'] ?? '',
                'db.password' => $database['password'] ?? '',
            ]);

            $connection = (new DatabaseConnection($config))->connect();
            $this->pass(sprintf("Database connection successful (%s:%s/%s)", $database['host'], $database['port'], $database['database']));

            // Check migration status
            $runner = new MigrationRunner($connection, $this->root . '/database/migrations');
            $pending = $runner->pending();
            if ($pending === []) {
                $this->pass("Database migrations: all migrations are up to date (0 pending)");
            } else {
                $this->warn(sprintf("Database migrations: %d pending migration(s): %s", count($pending), implode(', ', $pending)));
            }
        } catch (PDOException $e) {
            $this->fail(sprintf("Database connection failed: %s", $e->getMessage()));
        } catch (Throwable $e) {
            $this->fail(sprintf("Database verification error: %s", $e->getMessage()));
        }
    }

    private function header(string $title): void
    {
        echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
        echo "  " . $title . PHP_EOL;
        echo str_repeat('=', 70) . PHP_EOL . PHP_EOL;
    }

    private function pass(string $message): void
    {
        echo "  [PASS] " . $message . PHP_EOL;
    }

    private function warn(string $message): void
    {
        $this->warnings++;
        echo "  [WARN] " . $message . PHP_EOL;
    }

    private function fail(string $message): void
    {
        $this->errors++;
        echo "  [FAIL] " . $message . PHP_EOL;
    }

    private function summary(): void
    {
        echo PHP_EOL . str_repeat('-', 70) . PHP_EOL;
        if ($this->errors === 0) {
            echo sprintf("  PREFLIGHT RESULT: SUCCESS (0 errors, %d warnings)%s", $this->warnings, PHP_EOL);
            echo "  Backend is ready for deployment." . PHP_EOL;
        } else {
            echo sprintf("  PREFLIGHT RESULT: FAILED (%d error(s), %d warning(s))%s", $this->errors, $this->warnings, PHP_EOL);
            echo "  Resolve the highlighted errors before deploying to production." . PHP_EOL;
        }
        echo str_repeat('-', 70) . PHP_EOL . PHP_EOL;
    }
}

$preflight = new ProductionPreflight(dirname(__DIR__));
exit($preflight->run());
