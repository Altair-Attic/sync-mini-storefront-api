<?php

declare(strict_types=1);

namespace ProjectSync\Controllers;

use ProjectSync\Exceptions\CheckoutException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Infrastructure\RequestParser;
use ProjectSync\Services\CheckoutRateLimiter;
use ProjectSync\Services\CheckoutService;

final readonly class OrderConfirmationController
{
    public function __construct(private CheckoutService $checkout, private CheckoutRateLimiter $rateLimiter)
    {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    public function show(string $requestId, array $server, array $route): HttpResponse
    {
        $reference = $route['reference'] ?? '';
        $query = RequestParser::query($server);
        $token = $query['token'] ?? null;
        $ip = $this->ip($server);
        try {
            $this->rateLimiter->assertConfirmationAllowed($ip, $reference);
            if (!is_string($token) || $token === '') {
                throw new CheckoutException('ORDER_NOT_FOUND', 'The order was not found.', 404);
            }
            $order = $this->checkout->confirmation($reference, $token);
            $this->rateLimiter->clearConfirmation($ip, $reference);

            return JsonResponse::success($order, $requestId);
        } catch (CheckoutException $exception) {
            if ($exception->errorCode === 'ORDER_NOT_FOUND') {
                try {
                    $this->rateLimiter->recordInvalidConfirmation($ip, $reference);
                } catch (CheckoutException $rateLimit) {
                    return JsonResponse::error($rateLimit->errorCode, $rateLimit->getMessage(), $requestId, $rateLimit->status);
                }
            }

            return JsonResponse::error($exception->errorCode, $exception->getMessage(), $requestId, $exception->status);
        }
    }

    /** @param array<string, mixed> $server */
    private function ip(array $server): string
    {
        $ip = $server['REMOTE_ADDR'] ?? 'unknown';

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
