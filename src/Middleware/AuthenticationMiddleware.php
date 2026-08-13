<?php

declare(strict_types=1);

namespace ProjectSync\Middleware;

use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Services\AuthenticationService;

final readonly class AuthenticationMiddleware
{
    public function __construct(private AuthenticationService $authentication) {}

    /** @return array{id: string, name: string, email: string}|HttpResponse */
    public function requireAdministrator(string $requestId): array|HttpResponse
    {
        $administrator = $this->authentication->current();
        if ($administrator === null) return JsonResponse::error('UNAUTHENTICATED', 'Authentication is required.', $requestId, 401);

        return $administrator;
    }
}
