<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    public function __construct(
        public int $status,
        public array $headers,
        public array $body,
        public ?string $rawBody = null,
    ) {
    }
}
