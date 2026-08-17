<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use Closure;
use ProjectSync\Exceptions\ProductNotFoundException;
use ProjectSync\Exceptions\UploadException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Infrastructure\RequestParser;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Services\ProductImageService;

final readonly class ProductImageController
{
    /** @param Closure(): array<string, mixed> $readFiles */
    public function __construct(
        private ProductImageService $images,
        private AuthenticationMiddleware $authentication,
        private Closure $readFiles,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function upload(string $requestId, array $server, array $route): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }
        if (!RequestParser::isContentType($server, 'multipart/form-data')) {
            return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be multipart/form-data.', $requestId, 415);
        }
        $files = ($this->readFiles)();
        $image = $files['image'] ?? [];
        try {
            return JsonResponse::success($this->images->upload($route['id'] ?? '', is_array($image) ? $image : []), $requestId);
        } catch (UploadException $exception) {
            return JsonResponse::error($exception->errorCode, $exception->getMessage(), $requestId, $exception->httpStatus);
        } catch (ProductNotFoundException) {
            return JsonResponse::error('PRODUCT_NOT_FOUND', 'The product was not found.', $requestId, 404);
        }
    }
}
