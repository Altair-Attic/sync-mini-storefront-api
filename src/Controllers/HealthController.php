<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use PDO;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use Throwable;

final class HealthController
{
    public function __construct(
        private ?PDO $db = null,
    ) {
    }

    public function __invoke(string $requestId): HttpResponse
    {
        return JsonResponse::success(['status' => 'ok'], $requestId);
    }

    public function readiness(string $requestId): HttpResponse
    {
        if ($this->db === null) {
            return JsonResponse::success(['status' => 'ready'], $requestId);
        }

        try {
            $statement = $this->db->query('SELECT 1');
            if ($statement === false) {
                return JsonResponse::error('SERVICE_UNAVAILABLE', 'Service dependency check failed.', $requestId, 503);
            }

            return JsonResponse::success(['status' => 'ready', 'database' => 'connected'], $requestId);
        } catch (Throwable) {
            return JsonResponse::error('SERVICE_UNAVAILABLE', 'Service dependency check failed.', $requestId, 503);
        }
    }
}
