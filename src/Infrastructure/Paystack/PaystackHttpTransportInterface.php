<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Paystack;

interface PaystackHttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array;
}
