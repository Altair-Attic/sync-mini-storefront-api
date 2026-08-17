<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use ProjectSync\Exceptions\PaymentException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Infrastructure\RequestParser;
use ProjectSync\Services\PaymentRateLimiter;
use ProjectSync\Services\PaymentService;
use ProjectSync\Validators\PaymentInitializationValidator;

final readonly class PaymentController
{
    public function __construct(
        private PaymentService $payments,
        private PaymentInitializationValidator $validator,
        private PaymentRateLimiter $rateLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $params
     */
    public function initialize(string $requestId, array $server, array $params): HttpResponse
    {
        $orderReference = $params['reference'] ?? '';
        if ($orderReference === '') {
            return JsonResponse::error('ORDER_NOT_FOUND', 'The order was not found.', $requestId, 404);
        }

        try {
            $rawIdempotency = $server['HTTP_IDEMPOTENCY_KEY'] ?? null;
            $idempotencyKey = $this->validator->validateIdempotencyKey(is_string($rawIdempotency) ? $rawIdempotency : null);
            $query = RequestParser::query($server);
            $token = is_string($server['HTTP_X_CONFIRMATION_TOKEN'] ?? null)
                ? (string) $server['HTTP_X_CONFIRMATION_TOKEN']
                : (is_string($query['token'] ?? null) ? (string) $query['token'] : null);
            $confirmationToken = $this->validator->validateConfirmationToken($token);

            $this->rateLimiter->consumeInitialization($this->ip($server));

            $result = $this->payments->initialize($orderReference, $confirmationToken, $idempotencyKey);
            $statusCode = $result['idempotent_replay'] ? 200 : 201;

            return JsonResponse::success(
                data: $result,
                requestId: $requestId,
                status: $statusCode,
                meta: ['idempotent_replay' => $result['idempotent_replay']],
            );
        } catch (PaymentException $e) {
            return JsonResponse::error($e->errorCode, $e->getMessage(), $requestId, $e->status);
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $params
     */
    public function status(string $requestId, array $server, array $params): HttpResponse
    {
        $orderReference = $params['reference'] ?? '';
        $paymentReference = $params['paymentReference'] ?? '';
        if ($orderReference === '' || $paymentReference === '') {
            return JsonResponse::error('PAYMENT_NOT_FOUND', 'Payment not found.', $requestId, 404);
        }

        try {
            $query = RequestParser::query($server);
            $token = is_string($server['HTTP_X_CONFIRMATION_TOKEN'] ?? null)
                ? (string) $server['HTTP_X_CONFIRMATION_TOKEN']
                : (is_string($query['token'] ?? null) ? (string) $query['token'] : null);
            $confirmationToken = $this->validator->validateConfirmationToken($token);

            $this->rateLimiter->consumeStatus($this->ip($server), $orderReference);

            $data = $this->payments->status($orderReference, $confirmationToken, $paymentReference);

            return JsonResponse::success($data, $requestId, 200);
        } catch (PaymentException $e) {
            return JsonResponse::error($e->errorCode, $e->getMessage(), $requestId, $e->status);
        }
    }

    /** @param array<string, mixed> $server */
    private function ip(array $server): string
    {
        $ip = $server['REMOTE_ADDR'] ?? 'unknown';

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
