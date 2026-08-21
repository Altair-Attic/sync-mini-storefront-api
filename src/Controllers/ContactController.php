<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use Closure;
use JsonException;
use ProjectSync\Exceptions\BusinessProfileNotFoundException;
use ProjectSync\Exceptions\CheckoutException;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Infrastructure\RequestParser;
use ProjectSync\Services\CheckoutRateLimiter;
use ProjectSync\Services\ContactService;
use ProjectSync\Validators\ContactValidator;

final readonly class ContactController
{
    /** @param Closure(): string $readBody */
    public function __construct(private ContactValidator $validator, private ContactService $contact, private CheckoutRateLimiter $rateLimiter, private Closure $readBody)
    {
    }

    /** @param array<string, mixed> $server */
    public function send(string $requestId, array $server): HttpResponse
    {
        if (!RequestParser::isContentType($server, 'application/json')) return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', $requestId, 415);
        try {
            $request = $this->validator->validate(RequestParser::jsonObject(($this->readBody)()));
            $this->rateLimiter->consumeContact($this->ip($server));
            return JsonResponse::success($this->contact->send($request, $requestId), $requestId, 201);
        } catch (JsonException|ValidationException $exception) {
            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $exception instanceof ValidationException ? $exception->fields : []);
        } catch (CheckoutException $exception) {
            return JsonResponse::error($exception->errorCode, $exception->getMessage(), $requestId, $exception->status);
        } catch (BusinessProfileNotFoundException) {
            return JsonResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', $requestId, 500);
        }
    }

    /** @param array<string, mixed> $server */
    private function ip(array $server): string
    {
        return is_string($server['REMOTE_ADDR'] ?? null) && $server['REMOTE_ADDR'] !== '' ? $server['REMOTE_ADDR'] : 'unknown';
    }
}
