<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use Closure;
use ProjectSync\Exceptions\PaymentException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Services\PaymentService;

final readonly class PaymentWebhookController
{
    /**
     * @param Closure(): string $readRawBody
     */
    public function __construct(
        private PaymentService $payments,
        private Closure $readRawBody,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     */
    public function handle(string $requestId, array $server): HttpResponse
    {
        $signatureHeader = is_string($server['HTTP_X_PAYSTACK_SIGNATURE'] ?? null)
            ? (string) $server['HTTP_X_PAYSTACK_SIGNATURE']
            : '';

        $rawBody = ($this->readRawBody)();

        try {
            $result = $this->payments->handleWebhook($rawBody, $signatureHeader);

            return JsonResponse::success($result, $requestId, 200);
        } catch (PaymentException $e) {
            return JsonResponse::error($e->errorCode, $e->getMessage(), $requestId, $e->status);
        }
    }
}
