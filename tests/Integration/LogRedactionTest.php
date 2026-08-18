<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\LogRedactionProcessor;
use RuntimeException;

final class LogRedactionTest extends TestCase
{
    private LogRedactionProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new LogRedactionProcessor();
    }

    public function testRedactionOfPaystackKeys(): void
    {
        $rawMessage = 'Payment attempt initialized with key sk_live_99999999999999999999 and test key sk_test_1234567890abcdef.';
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $rawMessage,
            context: ['paystack_secret_key' => 'sk_live_super_secret_merchant_key'],
        );

        $processed = ($this->processor)($record);

        $this->assertStringNotContainsString('sk_live_99999999999999999999', $processed->message);
        $this->assertStringContainsString('sk_live_[REDACTED]', $processed->message);
        $this->assertStringContainsString('sk_test_[REDACTED]', $processed->message);
        $this->assertSame('[REDACTED]', $processed->context['paystack_secret_key']);
    }

    public function testRedactionOfJwtAndBearerTokens(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $rawMessage = 'User authenticated with Bearer ' . $jwt;
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $rawMessage,
            context: [
                'authorization' => 'Bearer ' . $jwt,
                'access_token' => $jwt,
            ],
        );

        $processed = ($this->processor)($record);

        $this->assertStringNotContainsString($jwt, $processed->message);
        $this->assertStringContainsString('[REDACTED_JWT]', $processed->message);
        $this->assertSame('[REDACTED]', $processed->context['authorization']);
        $this->assertSame('[REDACTED]', $processed->context['access_token']);
    }

    public function testRedactionOfPasswordsAndSecrets(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'Database connection failed',
            context: [
                'db_password' => 'super_secret_db_pass_123',
                'password' => 'admin_plain_password',
                'smtp_password' => 'mail_secret_pass',
                'jwt_secret' => '32_character_jwt_secret_value_here',
                'refresh_token_security_secret' => '32_char_refresh_secret_value_here',
                'order_security_secret' => 'order_sec_val',
                'nested' => [
                    'secret' => 'nested_secret_value',
                    'safe_field' => 'visible_order_reference_123',
                ],
            ],
        );

        $processed = ($this->processor)($record);

        $this->assertSame('[REDACTED]', $processed->context['db_password']);
        $this->assertSame('[REDACTED]', $processed->context['password']);
        $this->assertSame('[REDACTED]', $processed->context['smtp_password']);
        $this->assertSame('[REDACTED]', $processed->context['jwt_secret']);
        $this->assertSame('[REDACTED]', $processed->context['refresh_token_security_secret']);
        $this->assertSame('[REDACTED]', $processed->context['order_security_secret']);
        /** @var array<string, mixed> $nested */
        $nested = $processed->context['nested'];
        $this->assertSame('[REDACTED]', $nested['secret']);
        $this->assertSame('visible_order_reference_123', $nested['safe_field']);
    }

    public function testRedactionInsideExceptionTraces(): void
    {
        $exception = new RuntimeException('Error connecting with password=SuperSecretPassword123! and sk_live_999999999999');
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Critical,
            message: 'Uncaught exception',
            context: [
                'exception' => $exception,
            ],
        );

        $processed = ($this->processor)($record);

        $exceptionArray = $processed->context['exception'];
        $this->assertIsArray($exceptionArray);
        $message = is_string($exceptionArray['message'] ?? null) ? $exceptionArray['message'] : '';
        $this->assertStringNotContainsString('SuperSecretPassword123!', $message);
        $this->assertStringContainsString('password=[REDACTED]', $message);
        $this->assertStringContainsString('sk_live_[REDACTED]', $message);
    }
}
