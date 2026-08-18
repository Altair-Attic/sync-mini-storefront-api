<?php

declare(strict_types=1);

namespace ProjectSync\Middleware;

use ProjectSync\Infrastructure\HttpResponse;

final readonly class CorsMiddleware
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private array $allowedOrigins)
    {
    }

    public function preflight(string $method, ?string $origin, string $requestId): ?HttpResponse
    {
        if ($method !== 'OPTIONS') {
            return null;
        }
        if ($origin === null || !in_array($origin, $this->allowedOrigins, true)) {
            return new HttpResponse(204, ['Content-Type' => 'application/json; charset=utf-8'], []);
        }

        return new HttpResponse(204, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Request-Id, Idempotency-Key, X-Confirmation-Token',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ], []);
    }
}
