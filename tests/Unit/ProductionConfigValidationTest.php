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
            'app.debug' => true,
            'app.log_level' => 'debug',
            'auth.jwt_secret' => '12345678901234567890123456789012',
            'auth.jwt_issuer' => 'http://localhost:8000',
            'auth.jwt_audience' => 'http://localhost:8000/admin',
            'auth.jwt_access_ttl_seconds' => '900',
            'auth.jwt_clock_skew_seconds' => '30',
            'auth.jwt_algorithm' => 'HS256',
            'auth.refresh_token_ttl_seconds' => '2592000',
            'auth.refresh_cookie_name' => 'project_sync_refresh',
            'auth.refresh_cookie_path' => '/api/v1/admin',
            'auth.refresh_cookie_secure' => false,
            'auth.refresh_cookie_same_site' => 'Strict',
            'auth.refresh_token_security_secret' => 'abcdefabcdefabcdefabcdefabcdefab',
            'auth.application_origin' => 'http://localhost:8000',
            'paystack.secret_key' => 'sk_test_1234567890abcdef1234567890abcdef',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.timeout_seconds' => '10',
            'checkout.security_secret' => 'order_sec_123456789012345678901234',
            'login.rate_limit_secret' => 'rate_sec_123456789012345678901234',
            'notifications.security_secret' => 'notif_sec_123456789012345678901234',
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
            'http URL in production' => [
                ['auth.application_origin' => 'http://merchant.com'],
                'Production APP_URL must use HTTPS',
            ],
            'weak or placeholder order security secret' => [
                ['checkout.security_secret' => 'change-this-to-a-long-random-secret'],
                'Production security secrets must not use default placeholder values',
            ],
            'weak or placeholder login rate secret' => [
                ['login.rate_limit_secret' => 'change-this-per-deployment'],
                'Production security secrets must not use default placeholder values',
            ],
            'weak or placeholder notification security secret' => [
                ['notifications.security_secret' => 'change-this-to-an-independent-long-random-secret'],
                'Production security secrets must not use default placeholder values',
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
            'app.debug' => false,
            'app.log_level' => 'info',
            'auth.jwt_secret' => 'prod_jwt_secret_123456789012345678901234567890',
            'auth.jwt_issuer' => 'https://merchant.example.com',
            'auth.jwt_audience' => 'https://merchant.example.com/admin',
            'auth.jwt_access_ttl_seconds' => '900',
            'auth.jwt_clock_skew_seconds' => '30',
            'auth.jwt_algorithm' => 'HS256',
            'auth.refresh_token_ttl_seconds' => '2592000',
            'auth.refresh_cookie_name' => 'project_sync_refresh',
            'auth.refresh_cookie_path' => '/api/v1/admin',
            'auth.refresh_cookie_secure' => true,
            'auth.refresh_cookie_same_site' => 'Strict',
            'auth.refresh_token_security_secret' => 'prod_refresh_secret_123456789012345678901234567890',
            'auth.application_origin' => 'https://merchant.example.com',
            'paystack.secret_key' => 'sk_live_99999999999999999999999999999999',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.timeout_seconds' => '10',
            'checkout.security_secret' => 'prod_order_security_secret_1234567890',
            'login.rate_limit_secret' => 'prod_login_rate_limit_secret_1234567890',
            'notifications.security_secret' => 'prod_notifications_security_secret_1234567890',
            'db.host' => '127.0.0.1',
            'db.port' => '3306',
            'db.database' => 'project_sync_prod',
            'db.username' => 'project_sync_user',
            'db.password' => 'super_secret_password',
            'mail.enabled' => false,
        ];
    }
}
