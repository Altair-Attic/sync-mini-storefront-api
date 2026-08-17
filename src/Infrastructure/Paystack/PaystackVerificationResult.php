<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Paystack;

final readonly class PaystackVerificationResult
{
    public function __construct(
        public string $status,
        public string $reference,
        public int $amountKobo,
        public string $currency,
        public ?string $channel = null,
        public ?string $gatewayResponse = null,
        public ?string $paidAt = null,
        public ?int $paystackId = null,
    ) {
    }
}
