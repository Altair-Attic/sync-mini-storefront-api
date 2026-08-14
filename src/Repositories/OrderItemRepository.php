<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use RuntimeException;

final readonly class OrderItemRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @param list<array<string, int|string|null>> $items */
    public function insertMany(array $items): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO order_items (id, order_id, product_id, product_public_id, product_title, product_slug, unit_price_kobo, quantity, line_total_kobo, created_at) '
            . 'VALUES (:id, :order_id, :product_id, :product_public_id, :product_title, :product_slug, :unit_price_kobo, :quantity, :line_total_kobo, UTC_TIMESTAMP())'
        );
        foreach ($items as $item) {
            $statement->execute($item);
        }
    }

    /** @return list<array<string, mixed>> */
    public function findByOrderId(string $orderId): array
    {
        $statement = $this->db->prepare(
            'SELECT product_public_id, product_title, product_slug, unit_price_kobo, quantity, line_total_kobo '
            . 'FROM order_items WHERE order_id = :order_id ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['order_id' => $orderId]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid order item record.');
            }
            foreach (['product_public_id', 'product_title', 'product_slug'] as $field) {
                if (!isset($row[$field]) || !is_string($row[$field])) {
                    throw new RuntimeException('Invalid order item record.');
                }
            }
            foreach (['unit_price_kobo', 'quantity', 'line_total_kobo'] as $field) {
                $value = $row[$field] ?? null;
                if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                    throw new RuntimeException('Invalid order item record.');
                }
                $row[$field] = (int) $value;
            }
            $items[] = $row;
        }

        return $items;
    }
}
