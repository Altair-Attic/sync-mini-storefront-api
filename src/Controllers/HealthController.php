<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;

final class HealthController
{
    public function __invoke(string $requestId): HttpResponse
    {
        return JsonResponse::success(['status' => 'ok'], $requestId);
    }
}
