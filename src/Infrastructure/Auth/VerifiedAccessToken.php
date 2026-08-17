<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Auth;

final readonly class VerifiedAccessToken
{
    public function __construct(
        public string $administratorId,
        public string $jwtId,
        public int $expiresAt,
    ) {
    }
}
