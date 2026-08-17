<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use ProjectSync\Exceptions\InvalidOrderStatusTransitionException;
use ProjectSync\Exceptions\OrderNotFoundException;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\OrderStatusHistoryRepository;
use ProjectSync\Validators\OrderListQueryValidator;
use ProjectSync\Validators\OrderStatusUpdateValidator;
use Throwable;

final readonly class OrderManagementService
{
    /** @var array<string, list<string>> */
    private const array ALLOWED_TRANSITIONS = [
        'new' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private PDO $db,
        private OrderRepository $orders,
        private OrderItemRepository $orderItems,
        private OrderStatusHistoryRepository $statusHistory,
        private OrderListQueryValidator $queryValidator,
        private OrderStatusUpdateValidator $statusValidator,
        private ?NotificationService $notifications = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    public function list(array $query): array
    {
        $filters = $this->queryValidator->validate($query);
        $orders = $this->orders->findForAdminList($filters);
        $total = $this->orders->countForAdminList($filters);

        $items = [];
        foreach ($orders as $order) {
            $items[] = $this->formatOrderSummary($order);
        }

        $totalPages = $total === 0 ? 1 : (int) ceil($total / $filters['per_page']);

        return [
            'items' => $items,
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(string $idOrReference): array
    {
        $order = $this->orders->findForAdminDetail($idOrReference);
        if ($order === null) {
            throw new OrderNotFoundException('The order was not found.');
        }

        return $this->formatOrderDetail($order);
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return $this->orders->summaryCounts();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{order: array<string, mixed>, unchanged: bool}
     */
    public function updateStatus(string $idOrReference, array $input, ?string $adminUserId): array
    {
        $validated = $this->statusValidator->validate($input);
        $newStatus = $validated['status'];

        try {
            $this->db->beginTransaction();
            $order = $this->orders->findForUpdate($idOrReference);
            if ($order === null) {
                $this->rollback();
                throw new OrderNotFoundException('The order was not found.');
            }

            $currentStatus = $this->string($order, 'fulfilment_status');
            if ($currentStatus === $newStatus) {
                $this->rollback();

                return [
                    'order' => $this->formatOrderDetail($order),
                    'unchanged' => true,
                ];
            }

            $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($newStatus, $allowed, true)) {
                $this->rollback();
                throw new InvalidOrderStatusTransitionException($currentStatus, $newStatus);
            }

            $orderId = $this->string($order, 'id');
            $this->orders->updateFulfilmentStatus($orderId, $newStatus);
            $historyId = UuidGenerator::v4();
            $this->statusHistory->insert($historyId, $orderId, $currentStatus, $newStatus, $adminUserId);

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }

        // Notification is triggered asynchronously after transaction commits
        $order['fulfilment_status'] = $newStatus;
        if ($this->notifications !== null) {
            $this->notifications->notifyStatusChange($order, $newStatus);
        }

        return [
            'order' => $this->detail($orderId),
            'unchanged' => false,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function formatOrderSummary(array $order): array
    {
        return [
            'id' => $this->string($order, 'id'),
            'reference' => $this->string($order, 'reference'),
            'customer_name' => $this->string($order, 'customer_name'),
            'phone_number' => $this->string($order, 'phone_number'),
            'customer_email' => $this->nullableString($order, 'customer_email'),
            'fulfilment_method' => $this->string($order, 'fulfilment_method'),
            'delivery_address' => $this->nullableString($order, 'delivery_address'),
            'state' => $this->nullableString($order, 'state'),
            'subtotal_kobo' => $order['subtotal_kobo'] ?? 0,
            'delivery_fee_kobo' => $order['delivery_fee_kobo'] ?? 0,
            'total_kobo' => $order['total_kobo'] ?? 0,
            'currency' => $this->string($order, 'currency'),
            'payment_method' => $this->string($order, 'payment_method'),
            'payment_status' => $this->string($order, 'payment_status'),
            'fulfilment_status' => $this->string($order, 'fulfilment_status'),
            'created_at' => $this->formatDate($this->string($order, 'created_at')),
            'updated_at' => $this->formatDate($this->string($order, 'updated_at')),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function formatOrderDetail(array $order): array
    {
        $orderId = $this->string($order, 'id');
        $items = $this->orderItems->findByOrderId($orderId);
        $history = $this->statusHistory->findByOrderId($orderId);

        $formattedHistory = [];
        foreach ($history as $h) {
            $formattedHistory[] = [
                'id' => $h['id'],
                'previous_status' => $h['previous_status'],
                'new_status' => $h['new_status'],
                'changed_by' => $h['changed_by'],
                'created_at' => $this->formatDate($this->string($h, 'created_at')),
            ];
        }

        return [
            'id' => $orderId,
            'reference' => $this->string($order, 'reference'),
            'customer_name' => $this->string($order, 'customer_name'),
            'phone_number' => $this->string($order, 'phone_number'),
            'customer_email' => $this->nullableString($order, 'customer_email'),
            'fulfilment_method' => $this->string($order, 'fulfilment_method'),
            'delivery_address' => $this->nullableString($order, 'delivery_address'),
            'state' => $this->nullableString($order, 'state'),
            'subtotal_kobo' => $order['subtotal_kobo'] ?? 0,
            'delivery_fee_kobo' => $order['delivery_fee_kobo'] ?? 0,
            'total_kobo' => $order['total_kobo'] ?? 0,
            'currency' => $this->string($order, 'currency'),
            'payment_method' => $this->string($order, 'payment_method'),
            'payment_status' => $this->string($order, 'payment_status'),
            'fulfilment_status' => $this->string($order, 'fulfilment_status'),
            'items' => $items,
            'status_history' => $formattedHistory,
            'created_at' => $this->formatDate($this->string($order, 'created_at')),
            'updated_at' => $this->formatDate($this->string($order, 'updated_at')),
        ];
    }

    private function formatDate(string $dateString): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateString, new DateTimeZone('UTC'));
        if ($date === false) {
            $date = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $dateString);
        }

        return $date !== false ? $date->format('Y-m-d\TH:i:s\Z') : $dateString;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
