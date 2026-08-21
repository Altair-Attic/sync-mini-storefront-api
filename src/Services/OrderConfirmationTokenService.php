<?php

declare(strict_types=1);

namespace ProjectSync\Services;

final readonly class OrderConfirmationTokenService
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
