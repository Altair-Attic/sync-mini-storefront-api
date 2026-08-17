<?php

declare(strict_types=1);

namespace ProjectSync\Controllers\Admin;

use ProjectSync\Exceptions\PaymentException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\PaymentAttemptRepository;
use ProjectSync\Repositories\PaymentEventRepository;
use ProjectSync\Services\PaymentRateLimiter;
use ProjectSync\Services\PaymentService;

final readonly class AdminPaymentController
{
    public function __construct(
        private AuthenticationMiddleware $auth,
        private OrderRepository $orders,
        private PaymentAttemptRepository $attempts,
        private PaymentEventRepository $events,
        private PaymentService $payments,
        private PaymentRateLimiter $rateLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $params
     */
    public function list(string $requestId, array $server, array $params): HttpResponse
    {
        $authenticated = $this->auth->requireAdministrator($requestId, $server);
        if ($authenticated instanceof HttpResponse) {
            return $authenticated;
        }

        $orderParam = $params['orderId'] ?? '';
        $order = $this->orders->findById($orderParam) ?? $this->orders->findByReference($orderParam);
        if ($order === null) {
            return JsonResponse::error('ORDER_NOT_FOUND', 'Order not found.', $requestId, 404);
        }

        $orderId = is_string($order['id'] ?? null) ? (string) $order['id'] : '';
        $paymentAttempts = $this->attempts->listByOrderId($orderId);
        $paymentEvents = $this->events->listByOrderId($orderId);

        $orderRef = is_string($order['reference'] ?? null) ? (string) $order['reference'] : '';
        $paymentStatus = is_string($order['payment_status'] ?? null) ? (string) $order['payment_status'] : '';
        $fulfilmentStatus = is_string($order['fulfilment_status'] ?? null) ? (string) $order['fulfilment_status'] : '';
        $rawTotal = $order['total_kobo'] ?? 0;
        $totalKobo = is_int($rawTotal) ? $rawTotal : (is_numeric($rawTotal) ? (int) $rawTotal : 0);
        $currency = is_string($order['currency'] ?? null) ? (string) $order['currency'] : 'NGN';

        return JsonResponse::success([
            'order_id' => $orderId,
            'order_reference' => $orderRef,
            'payment_status' => $paymentStatus,
            'fulfilment_status' => $fulfilmentStatus,
            'total_kobo' => $totalKobo,
            'currency' => $currency,
            'payments' => $paymentAttempts,
            'events' => $paymentEvents,
        ], $requestId, 200);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $params
     */
    public function reconcile(string $requestId, array $server, array $params): HttpResponse
    {
        $authenticated = $this->auth->requireAdministrator($requestId, $server);
        if ($authenticated instanceof HttpResponse) {
            return $authenticated;
        }

        $adminUser = $authenticated;
        $adminId = $adminUser['id'];

        $orderParam = $params['orderId'] ?? '';
        $paymentId = $params['paymentId'] ?? '';
        $order = $this->orders->findById($orderParam) ?? $this->orders->findByReference($orderParam);
        if ($order === null) {
            return JsonResponse::error('ORDER_NOT_FOUND', 'Order not found.', $requestId, 404);
        }

        $orderId = is_string($order['id'] ?? null) ? (string) $order['id'] : '';

        try {
            $this->rateLimiter->consumeReconcile($adminId);
            $result = $this->payments->reconcile($orderId, $paymentId);

            return JsonResponse::success($result, $requestId, 200);
        } catch (PaymentException $e) {
            return JsonResponse::error($e->errorCode, $e->getMessage(), $requestId, $e->status);
        }
    }
}
