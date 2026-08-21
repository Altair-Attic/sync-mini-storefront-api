<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use Closure;
use JsonException;
use ProjectSync\Exceptions\InvalidCategoryException;
use ProjectSync\Exceptions\ProductNotFoundException;
use ProjectSync\Exceptions\ProductSlugConflictException;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Infrastructure\RequestParser;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Services\ProductService;

final readonly class ProductController
{
    /** @param Closure(): string $readBody */
    public function __construct(
        private ProductService $products,
        private AuthenticationMiddleware $authentication,
        private Closure $readBody,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function publicList(string $requestId, array $server, array $route = []): HttpResponse
    {
        try {
            return $this->pageResponse($this->products->publicList(RequestParser::query($server)), $requestId);
        } catch (ValidationException $exception) {
            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $exception->fields);
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function publicShow(string $requestId, array $server, array $route): HttpResponse
    {
        try {
            return JsonResponse::success($this->products->publicDetail($route['slug'] ?? ''), $requestId);
        } catch (ProductNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function adminList(string $requestId, array $server, array $route = []): HttpResponse
    {
        $failure = $this->authenticate($requestId, $server);
        if ($failure !== null) {
            return $failure;
        }
        try {
            return $this->pageResponse($this->products->adminList(RequestParser::query($server)), $requestId);
        } catch (ValidationException $exception) {
            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $exception->fields);
        }
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
            return JsonResponse::success($this->products->find($route['id'] ?? ''), $requestId);
        } catch (ProductNotFoundException) {
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
    public function updateAvailability(string $requestId, array $server, array $route): HttpResponse
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
            $available = null;
            $errors = [];
            foreach ($payload as $key => $value) {
                if ($key === 'available' || $key === 'is_available') {
                    if (!is_bool($value)) {
                        $errors[$key] = ['Enter true or false.'];
                    } else {
                        $available = $value;
                    }
                } else {
                    $errors[$key] = ['Unknown field.'];
                }
            }
            if ($available === null && !isset($errors['available']) && !isset($errors['is_available'])) {
                $errors['available'] = ['The available field is required.'];
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $product = $this->products->updateAvailability($route['id'] ?? '', (bool) $available);

            return JsonResponse::success($product, $requestId, 200);
        } catch (JsonException|ValidationException $exception) {
            $fields = $exception instanceof ValidationException ? $exception->fields : [];

            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $fields);
        } catch (ProductNotFoundException) {
            return $this->notFound($requestId);
        }
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
            return JsonResponse::success($this->products->delete($route['id'] ?? ''), $requestId);
        } catch (ProductNotFoundException) {
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
            $product = $id === null ? $this->products->create($payload) : $this->products->update($id, $payload);

            return JsonResponse::success($product, $requestId, $id === null ? 201 : 200);
        } catch (JsonException|ValidationException $exception) {
            $fields = $exception instanceof ValidationException ? $exception->fields : [];

            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $fields);
        } catch (InvalidCategoryException) {
            return JsonResponse::error('INVALID_CATEGORY', 'Select an active category.', $requestId, 422, ['category_id' => ['The category is missing or inactive.']]);
        } catch (ProductSlugConflictException) {
            return JsonResponse::error('PRODUCT_SLUG_CONFLICT', 'That product slug is already in use.', $requestId, 409);
        } catch (ProductNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /** @param array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int} $page */
    private function pageResponse(array $page, string $requestId): HttpResponse
    {
        return JsonResponse::success($page['items'], $requestId, 200, [
            'page' => $page['page'],
            'per_page' => $page['per_page'],
            'total' => $page['total'],
            'total_pages' => $page['total_pages'],
        ]);
    }

    /** @param array<string, mixed> $server */
    private function authenticate(string $requestId, array $server): ?HttpResponse
    {
        $result = $this->authentication->requireAdministrator($requestId, $server);

        return $result instanceof HttpResponse ? $result : null;
    }

    private function notFound(string $requestId): HttpResponse
    {
        return JsonResponse::error('PRODUCT_NOT_FOUND', 'The product was not found.', $requestId, 404);
    }
}
