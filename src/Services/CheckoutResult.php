<?php

declare(strict_types=1);

namespace ProjectSync\Services;

final readonly class CheckoutResult
{
    /** @param array<string, mixed> $order */
    public function __construct(
        public array $order,
        public string $confirmationToken,
        public bool $replay,
    ) {
    }
}
