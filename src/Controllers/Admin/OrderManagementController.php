<?php

declare(strict_types=1);

namespace ProjectSync\Controllers\Admin;

use Closure;
use JsonException;
use ProjectSync\Exceptions\InvalidOrderStatusTransitionException;
use ProjectSync\Exceptions\OrderNotFoundException;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Infrastructure\RequestParser;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Services\OrderManagementService;

final readonly class OrderManagementController
{
    /** @param Closure(): string $readBody */
    public function __construct(
        private OrderManagementService $orders,
        private AuthenticationMiddleware $authentication,
        private Closure $readBody,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function list(string $requestId, array $server, array $route = []): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }

        try {
            $page = $this->orders->list(RequestParser::query($server));

            return JsonResponse::success($page['items'], $requestId, 200, [
                'page' => $page['page'],
                'per_page' => $page['per_page'],
                'total' => $page['total'],
                'total_pages' => $page['total_pages'],
            ]);
        } catch (ValidationException $exception) {
            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $exception->fields);
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function summary(string $requestId, array $server, array $route = []): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }

        $summary = $this->orders->summary();

        return JsonResponse::success(['summary' => $summary], $requestId, 200);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function dashboard(string $requestId, array $server, array $route = []): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }

        return JsonResponse::success(['dashboard' => $this->orders->dashboard()], $requestId, 200);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function detail(string $requestId, array $server, array $route): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }

        $orderId = $route['id'] ?? '';
        try {
            $order = $this->orders->detail($orderId);

            return JsonResponse::success(['order' => $order], $requestId, 200);
        } catch (OrderNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function updateStatus(string $requestId, array $server, array $route): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }
        $adminUserId = $administrator['id'];

        if (!RequestParser::isContentType($server, 'application/json')) {
            return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', $requestId, 415);
        }

        $orderId = $route['id'] ?? '';
        try {
            $payload = RequestParser::jsonObject(($this->readBody)());
            $result = $this->orders->updateStatus($orderId, $payload, $adminUserId);

            return JsonResponse::success(['order' => $result['order']], $requestId, 200, [
                'idempotent_replay' => $result['unchanged'],
            ]);
        } catch (JsonException|ValidationException $exception) {
            $fields = $exception instanceof ValidationException ? $exception->fields : [];

            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $fields);
        } catch (InvalidOrderStatusTransitionException $exception) {
            return JsonResponse::error(
                'INVALID_STATUS_TRANSITION',
                $exception->getMessage(),
                $requestId,
                409,
                ['status' => ["Cannot transition order status from '{$exception->previousStatus}' to '{$exception->newStatus}'."]],
            );
        } catch (OrderNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    private function notFound(string $requestId): HttpResponse
    {
        return JsonResponse::error('ORDER_NOT_FOUND', 'The order was not found.', $requestId, 404);
    }
}
