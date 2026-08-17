<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use RuntimeException;

final readonly class OrderStatusHistoryRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function insert(string $id, string $orderId, ?string $previousStatus, string $newStatus, ?string $changedBy): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO order_status_history (id, order_id, previous_status, new_status, changed_by, created_at) '
            . 'VALUES (:id, :order_id, :previous_status, :new_status, :changed_by, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'id' => $id,
            'order_id' => $orderId,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'changed_by' => $changedBy,
        ]);
    }

    /**
     * @return list<array{id: string, order_id: string, previous_status: string|null, new_status: string, changed_by: string|null, created_at: string}>
     */
    public function findByOrderId(string $orderId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, order_id, previous_status, new_status, changed_by, created_at '
            . 'FROM order_status_history WHERE order_id = :order_id ORDER BY created_at ASC'
        );
        $statement->execute(['order_id' => $orderId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $history = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid order status history record.');
            }
            $history[] = $this->mapRow($row);
        }

        return $history;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array{id: string, order_id: string, previous_status: string|null, new_status: string, changed_by: string|null, created_at: string}
     */
    private function mapRow(array $row): array
    {
        foreach (['id', 'order_id', 'new_status', 'created_at'] as $field) {
            if (!isset($row[$field]) || !is_string($row[$field])) {
                throw new RuntimeException('Invalid order status history record.');
            }
        }
        $previousStatus = $row['previous_status'] ?? null;
        if ($previousStatus !== null && !is_string($previousStatus)) {
            throw new RuntimeException('Invalid order status history record.');
        }
        $changedBy = $row['changed_by'] ?? null;
        if ($changedBy !== null && !is_string($changedBy)) {
            throw new RuntimeException('Invalid order status history record.');
        }

        return [
            'id' => $row['id'],
            'order_id' => $row['order_id'],
            'previous_status' => $previousStatus,
            'new_status' => $row['new_status'],
            'changed_by' => $changedBy,
            'created_at' => $row['created_at'],
        ];
    }
}
