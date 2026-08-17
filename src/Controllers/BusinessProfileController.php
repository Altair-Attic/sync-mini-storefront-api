<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use Closure;
use JsonException;
use ProjectSync\Exceptions\BusinessProfileNotFoundException;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Services\BusinessProfileService;

final readonly class BusinessProfileController
{
    /** @param Closure(): string $readBody */
    public function __construct(
        private BusinessProfileService $profiles,
        private AuthenticationMiddleware $authentication,
        private Closure $readBody,
    ) {
    }

    public function store(string $requestId): HttpResponse
    {
        try {
            return JsonResponse::success(['profile' => $this->profiles->publicProfile()], $requestId);
        } catch (BusinessProfileNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /** @param array<string, mixed> $server */
    public function admin(string $requestId, array $server): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }

        try {
            return JsonResponse::success(['profile' => $this->profiles->adminProfile()], $requestId);
        } catch (BusinessProfileNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    /** @param array<string, mixed> $server */
    public function update(string $requestId, array $server): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }
        if (!$this->isJson($server['CONTENT_TYPE'] ?? null)) {
            return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', $requestId, 415);
        }

        try {
            $payload = $this->payload(($this->readBody)());
            return JsonResponse::success(['profile' => $this->profiles->update($payload)], $requestId);
        } catch (JsonException|ValidationException $exception) {
            $fields = $exception instanceof ValidationException ? $exception->fields : [];

            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $fields);
        } catch (BusinessProfileNotFoundException) {
            return $this->notFound($requestId);
        }
    }

    private function isJson(mixed $contentType): bool
    {
        if (!is_string($contentType)) {
            return false;
        }
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json';
    }

    /** @return array<string, mixed> */
    private function payload(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new JsonException('The JSON body must be an object.');
        }
        $payload = [];
        foreach ($decoded as $field => $value) {
            if (!is_string($field)) {
                throw new JsonException('The JSON object must use named fields.');
            }
            $payload[$field] = $value;
        }

        return $payload;
    }

    private function notFound(string $requestId): HttpResponse
    {
        return JsonResponse::error('BUSINESS_PROFILE_NOT_FOUND', 'The business profile was not found.', $requestId, 404);
    }
}
