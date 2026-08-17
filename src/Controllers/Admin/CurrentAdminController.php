<?php

declare(strict_types=1);

namespace ProjectSync\Controllers\Admin;

use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Middleware\AuthenticationMiddleware;

final readonly class CurrentAdminController
{
    public function __construct(private AuthenticationMiddleware $authentication) {}
    /** @param array<string, mixed> $server */
    public function me(string $requestId, array $server): HttpResponse { $result = $this->authentication->requireAdministrator($requestId, $server); return $result instanceof HttpResponse ? $result : JsonResponse::success(['administrator' => $result], $requestId); }
}
