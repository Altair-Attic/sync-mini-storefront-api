<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ConfigurationException;
use ProjectSync\Infrastructure\AppFactory;
use ProjectSync\Infrastructure\Config;
use ReflectionClass;

final class ProductionConfigValidationTest extends TestCase
{
    /**
     * @dataProvider invalidProductionConfigProvider
     * @param array<string, mixed> $overrideValues
     */
    public function testProductionRejectsInvalidConfiguration(array $overrideValues, string $expectedMessageSnippet): void
    {
        $baseConfig = $this->createValidProductionConfigArray();
        $merged = array_merge($baseConfig, $overrideValues);
        $config = new Config($merged);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageSnippet, '/') . '/i');

        $ref = new ReflectionClass(AppFactory::class);
        $method1 = $ref->getMethod('validateProductionEnvironmentConfig');
        $method1->invoke(null, $config);

        $method2 = $ref->getMethod('validateAuthenticationConfig');
        $method2->invoke(null, $config);

        $method3 = $ref->getMethod('validatePaystackConfig');
        $method3->invoke(null, $config);
    }

    public function testProductionAcceptsValidConfig(): void
    {
        $this->expectNotToPerformAssertions();
        $config = new Config($this->createValidProductionConfigArray());

        $ref = new ReflectionClass(AppFactory::class);
        $method1 = $ref->getMethod('validateProductionEnvironmentConfig');
        $method1->invoke(null, $config);

        $method2 = $ref->getMethod('validateAuthenticationConfig');
        $method2->invoke(null, $config);

        $method3 = $ref->getMethod('validatePaystackConfig');
        $method3->invoke(null, $config);
    }

    public function testNonProductionAcceptsTestKeysAndHttp(): void
    {
        $this->expectNotToPerformAssertions();
        $config = new Config([
            'app.environment' => 'local',
            'app.url' => 'http://localhost:8000',
            'app.debug' => true,
            'app.log_level' => 'debug',
            'auth.jwt_secret' => '12345678901234567890123456789012',
            'auth.jwt_access_ttl_seconds' => '28800',
            'auth.jwt_algorithm' => 'HS256',
            'paystack.secret_key' => 'sk_test_1234567890abcdef1234567890abcdef',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.timeout_seconds' => '10',
            'db.host' => '127.0.0.1',
            'db.port' => '3306',
            'db.database' => 'project_sync',
            'db.username' => 'project_sync',
            'db.password' => '',
            'mail.enabled' => false,
        ]);

        $ref = new ReflectionClass(AppFactory::class);
        $ref->getMethod('validateProductionEnvironmentConfig')->invoke(null, $config);
        $ref->getMethod('validateAuthenticationConfig')->invoke(null, $config);
        $ref->getMethod('validatePaystackConfig')->invoke(null, $config);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidProductionConfigProvider(): array
    {
        return [
            'debug mode enabled in production' => [
                ['app.debug' => true],
                'Production environment must not have debug mode enabled',
            ],
            'test paystack key in production' => [
                ['paystack.secret_key' => 'sk_test_1234567890abcdef1234567890abcdef'],
                'Production Paystack secret key must be configured with a live sk_live_ key',
            ],
            'placeholder paystack key in production' => [
                ['paystack.secret_key' => 'sk_live_change-this-key'],
                'Production Paystack secret key must be configured with a live sk_live_ key',
            ],
            'http paystack base url in production' => [
                ['paystack.base_url' => 'http://api.paystack.co'],
                'Production Paystack base URL must use HTTPS',
            ],
            'mail enabled with incomplete host' => [
                ['mail.enabled' => true, 'mail.host' => ''],
                'Production email configuration is incomplete while MAIL_ENABLED=true',
            ],
            'mail enabled with incomplete from address' => [
                ['mail.enabled' => true, 'mail.host' => 'smtp.mailgun.org', 'mail.username' => 'user', 'mail.from_address' => ''],
                'Production email configuration is incomplete while MAIL_ENABLED=true',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function createValidProductionConfigArray(): array
    {
        return [
            'app.environment' => 'production',
            'app.url' => 'https://merchant.example.com',
            'app.debug' => false,
            'app.log_level' => 'info',
            'auth.jwt_secret' => 'prod_jwt_secret_123456789012345678901234567890',
            'auth.jwt_access_ttl_seconds' => '28800',
            'auth.jwt_algorithm' => 'HS256',
            'paystack.secret_key' => 'sk_live_99999999999999999999999999999999',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.timeout_seconds' => '10',
            'db.host' => '127.0.0.1',
            'db.port' => '3306',
            'db.database' => 'project_sync_prod',
            'db.username' => 'project_sync_user',
            'db.password' => 'super_secret_password',
            'mail.enabled' => false,
        ];
    }
}
