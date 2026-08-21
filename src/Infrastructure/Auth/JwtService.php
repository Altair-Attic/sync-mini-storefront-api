<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

final readonly class JwtService
{
    public function __construct(
        private string $secret,
        private int $ttlSeconds,
        private string $algorithm,
    ) {
        if ($algorithm !== 'HS256') {
            throw new RuntimeException('JWT algorithm must be HS256.');
        }
    }

    /** @return array{access_token: string, token_type: string, expires_in: int} */
    public function issue(string $administratorId): array
    {
        $now = time();
        $claims = [
            'sub' => $administratorId,
            'exp' => $now + $this->ttlSeconds,
        ];

        return [
            'access_token' => JWT::encode($claims, $this->secret, $this->algorithm),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttlSeconds,
        ];
    }

    public function verify(string $token): string
    {
        try {
            $claims = JWT::decode($token, new Key($this->secret, $this->algorithm));
            if (!is_string($claims->sub) || $claims->sub === '') {
                throw new RuntimeException('Invalid access token.');
            }

            return $claims->sub;
        } catch (Throwable $exception) {
            throw new RuntimeException('Invalid access token.', 0, $exception);
        }
    }
}
