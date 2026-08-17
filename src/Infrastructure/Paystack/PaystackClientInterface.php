<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Paystack;

interface PaystackClientInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function initializeTransaction(
        string $email,
        int $amountKobo,
        string $currency,
        string $reference,
        array $metadata = [],
    ): PaystackInitializationResult;

    public function verifyTransaction(string $reference): PaystackVerificationResult;

    public function verifySignature(string $rawBody, string $signatureHeader): bool;
}
