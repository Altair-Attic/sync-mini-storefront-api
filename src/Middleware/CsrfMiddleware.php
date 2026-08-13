<?php

declare(strict_types=1);

namespace ProjectSync\Middleware;

use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Services\CsrfTokenService;
use Psr\Log\LoggerInterface;

final readonly class CsrfMiddleware
{
    public function __construct(private CsrfTokenService $csrf, private LoggerInterface $logger) {}

    /** @param array<string, mixed> $server */
    public function requireValidToken(string $requestId, array $server): ?HttpResponse
    {
        $token = $server['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($token) && $this->csrf->valid($token)) return null;
        $this->logger->warning('Invalid CSRF attempt.', ['request_id' => $requestId]);

        return JsonResponse::error('CSRF_TOKEN_INVALID', 'The request could not be verified.', $requestId, 403);
    }
}
