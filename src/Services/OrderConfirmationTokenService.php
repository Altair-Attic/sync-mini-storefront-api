<?php

declare(strict_types=1);

namespace ProjectSync\Services;

final readonly class OrderConfirmationTokenService
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('ORDER_SECURITY_SECRET must contain at least 32 characters.');
        }
    }

    public function idempotencyHash(string $key): string
    {
        return hash_hmac('sha256', 'idempotency-v1|' . $key, $this->secret);
    }

    public function token(string $idempotencyHash): string
    {
        $bytes = hash_hmac('sha256', 'confirmation-v1|' . $idempotencyHash, $this->secret, true);

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function valid(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->tokenHash($token));
    }
}
