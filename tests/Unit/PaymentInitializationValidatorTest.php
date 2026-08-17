<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\PaymentException;
use ProjectSync\Validators\PaymentInitializationValidator;

final class PaymentInitializationValidatorTest extends TestCase
{
    private PaymentInitializationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PaymentInitializationValidator(200);
    }

    public function testValidIdempotencyKey(): void
    {
        $key = 'idem-key-1234567890abcdef';
        self::assertSame($key, $this->validator->validateIdempotencyKey($key));
    }

    public function testMissingIdempotencyKeyThrowsException(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Idempotency-Key header is required.');

        $this->validator->validateIdempotencyKey(null);
    }

    public function testTooShortIdempotencyKeyThrowsException(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Idempotency-Key must be 16 to 200 printable ASCII characters.');

        $this->validator->validateIdempotencyKey('short-key-123');
    }

    public function testValidConfirmationToken(): void
    {
        $token = 'CONFIRM-TOKEN-1234567890abcdef';
        self::assertSame($token, $this->validator->validateConfirmationToken($token));
    }

    public function testMissingConfirmationTokenThrowsException(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Order confirmation token is required.');

        $this->validator->validateConfirmationToken(null);
    }
}
