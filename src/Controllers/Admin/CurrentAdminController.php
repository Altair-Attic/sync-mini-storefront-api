<?php

declare(strict_types=1);

namespace ProjectSync\Controllers\Admin;

use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Middleware\AuthenticationMiddleware;

final readonly class CurrentAdminController
{
    public function __construct(private AuthenticationMiddleware $authentication) {}
    public function me(string $requestId): HttpResponse { $result = $this->authentication->requireAdministrator($requestId); return $result instanceof HttpResponse ? $result : JsonResponse::success(['user' => $result], $requestId); }
}
