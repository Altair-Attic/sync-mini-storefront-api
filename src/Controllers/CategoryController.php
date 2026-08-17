<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use Closure;
use JsonException;
use ProjectSync\Exceptions\CategoryNotFoundException;
use ProjectSync\Exceptions\CategorySlugConflictException;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Infrastructure\RequestParser;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Services\CategoryService;

final readonly class CategoryController
{
    /** @param Closure(): string $readBody */
    public function __construct(
        private CategoryService $categories,
        private AuthenticationMiddleware $authentication,
        private Closure $readBody,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function publicList(string $requestId, array $server = [], array $route = []): HttpResponse
    {
        return JsonResponse::success($this->categories->publicList(), $requestId);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function adminList(string $requestId, array $server = [], array $route = []): HttpResponse
    {
        $failure = $this->authenticate($requestId, $server);

        return $failure ?? JsonResponse::success($this->categories->adminList(), $requestId);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function show(string $requestId, array $server, array $route): HttpResponse
    {
        $failure = $this->authenticate($requestId, $server);
        if ($failure !== null) {
            return $failure;
        }
        try {
            return JsonResponse::success($this->categories->find($route['id'] ?? ''), $requestId);
        } catch (CategoryNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function create(string $requestId, array $server, array $route = []): HttpResponse
    {
        return $this->write($requestId, $server, null);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function update(string $requestId, array $server, array $route): HttpResponse
    {
        return $this->write($requestId, $server, $route['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function delete(string $requestId, array $server, array $route): HttpResponse
    {
        $failure = $this->authenticate($requestId, $server);
        if ($failure !== null) {
            return $failure;
        }
        try {
            return JsonResponse::success($this->categories->deactivate($route['id'] ?? ''), $requestId);
        } catch (CategoryNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /** @param array<string, mixed> $server */
    private function write(string $requestId, array $server, ?string $id): HttpResponse
    {
        $failure = $this->authenticate($requestId, $server);
        if ($failure !== null) {
            return $failure;
        }
        if (!RequestParser::isContentType($server, 'application/json')) {
            return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', $requestId, 415);
        }
        try {
            $payload = RequestParser::jsonObject(($this->readBody)());
            $category = $id === null ? $this->categories->create($payload) : $this->categories->update($id, $payload);

            return JsonResponse::success($category, $requestId, $id === null ? 201 : 200);
        } catch (JsonException|ValidationException $exception) {
            $fields = $exception instanceof ValidationException ? $exception->fields : [];

            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $fields);
        } catch (CategorySlugConflictException) {
            return JsonResponse::error('CATEGORY_SLUG_CONFLICT', 'That category slug is already in use.', $requestId, 409);
        } catch (CategoryNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /** @param array<string, mixed> $server */
    private function authenticate(string $requestId, array $server): ?HttpResponse
    {
        $result = $this->authentication->requireAdministrator($requestId, $server);

        return $result instanceof HttpResponse ? $result : null;
    }

    private function notFound(string $requestId): HttpResponse
    {
        return JsonResponse::error('CATEGORY_NOT_FOUND', 'The category was not found.', $requestId, 404);
    }
}
