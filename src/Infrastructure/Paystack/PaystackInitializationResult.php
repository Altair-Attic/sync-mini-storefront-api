<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Paystack;

final readonly class PaystackInitializationResult
{
    public function __construct(
        public string $authorizationUrl,
        public string $accessCode,
        public string $reference,
    ) {
    }
}
