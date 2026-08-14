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
use ProjectSync\Services\CheckoutService;
use ProjectSync\Validators\CheckoutValidator;

final readonly class OrderController
{
    /** @param Closure(): string $readBody */
    public function __construct(
        private CheckoutValidator $validator,
        private CheckoutService $checkout,
        private CheckoutRateLimiter $rateLimiter,
        private Closure $readBody,
    ) {
    }

    /** @param array<string, mixed> $server */
    public function create(string $requestId, array $server): HttpResponse
    {
        if (!RequestParser::isContentType($server, 'application/json')) {
            return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', $requestId, 415);
        }
        try {
            $request = $this->validator->validate(RequestParser::jsonObject(($this->readBody)()));
            $idempotencyKey = $this->validator->validateIdempotencyKey($server['HTTP_IDEMPOTENCY_KEY'] ?? null);
            $this->rateLimiter->consumeCheckout($this->ip($server));
            $result = $this->checkout->create($request, $idempotencyKey);
            $data = ['confirmation_token' => $result->confirmationToken] + $result->order;

            return JsonResponse::success($data, $requestId, $result->replay ? 200 : 201, ['idempotent_replay' => $result->replay]);
        } catch (JsonException|ValidationException $exception) {
            $fields = $exception instanceof ValidationException ? $exception->fields : [];

            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $fields);
        } catch (CheckoutException $exception) {
            return JsonResponse::error($exception->errorCode, $exception->getMessage(), $requestId, $exception->status);
        } catch (BusinessProfileNotFoundException) {
            return JsonResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', $requestId, 500);
        }
    }

    /** @param array<string, mixed> $server */
    private function ip(array $server): string
    {
        $ip = $server['REMOTE_ADDR'] ?? 'unknown';

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
