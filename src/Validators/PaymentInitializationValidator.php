<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\PaymentException;

final class PaymentInitializationValidator
{
    public function __construct(private readonly int $maxIdempotencyLength = 200)
    {
    }

    public function validateIdempotencyKey(?string $key): string
    {
        if ($key === null || trim($key) === '') {
            throw new PaymentException('IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key header is required.', 400);
        }
        $key = trim($key);
        $length = strlen($key);
        if ($length < 16 || $length > $this->maxIdempotencyLength || preg_match('/^[\x20-\x7E]+$/', $key) !== 1) {
            throw new PaymentException('IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key must be 16 to ' . $this->maxIdempotencyLength . ' printable ASCII characters.', 400);
        }

        return $key;
    }

    public function validateConfirmationToken(?string $token): string
    {
        if ($token === null || trim($token) === '') {
            throw new PaymentException('CONFIRMATION_TOKEN_REQUIRED', 'Order confirmation token is required.', 401);
        }
        $token = trim($token);
        if (strlen($token) < 16 || strlen($token) > 200 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new PaymentException('CONFIRMATION_TOKEN_INVALID', 'Invalid order confirmation token format.', 401);
        }

        return $token;
    }
}
