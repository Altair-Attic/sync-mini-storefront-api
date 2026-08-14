<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use RuntimeException;

final readonly class OrderRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyHash(string $hash): ?array
    {
        return $this->find('idempotency_key_hash = :value', $hash);
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        return $this->find('reference = :value', $reference);
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->find('id = :value', $id);
    }

    /** @param array<string, int|string|null> $order */
    public function insert(array $order): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO orders (id, reference, confirmation_token_hash, idempotency_key_hash, request_fingerprint, customer_name, phone_number, customer_email, fulfilment_method, delivery_address, state, subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, fulfilment_status, created_at, updated_at) '
            . 'VALUES (:id, :reference, :confirmation_token_hash, :idempotency_key_hash, :request_fingerprint, :customer_name, :phone_number, :customer_email, :fulfilment_method, :delivery_address, :state, :subtotal_kobo, :delivery_fee_kobo, :total_kobo, :currency, :payment_method, :payment_status, :fulfilment_status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute($order);
    }

    /** @return array<string, mixed>|null */
    private function find(string $where, string $value): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, reference, confirmation_token_hash, idempotency_key_hash, request_fingerprint, customer_name, phone_number, customer_email, fulfilment_method, delivery_address, state, subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, fulfilment_status, created_at, updated_at '
            . 'FROM orders WHERE ' . $where . ' LIMIT 1'
        );
        $statement->execute(['value' => $value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Invalid order record.');
        }

        return $this->order($row);
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function order(array $row): array
    {
        $strings = [
            'id', 'reference', 'confirmation_token_hash', 'idempotency_key_hash', 'request_fingerprint',
            'customer_name', 'phone_number', 'fulfilment_method', 'currency', 'payment_method',
            'payment_status', 'fulfilment_status', 'created_at', 'updated_at',
        ];
        foreach ($strings as $field) {
            if (!isset($row[$field]) || !is_string($row[$field])) {
                throw new RuntimeException('Invalid order record.');
            }
        }
        foreach (['customer_email', 'delivery_address', 'state'] as $field) {
            if (($row[$field] ?? null) !== null && !is_string($row[$field])) {
                throw new RuntimeException('Invalid order record.');
            }
        }
        foreach (['subtotal_kobo', 'delivery_fee_kobo', 'total_kobo'] as $field) {
            $value = $row[$field] ?? null;
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new RuntimeException('Invalid order record.');
            }
            $row[$field] = (int) $value;
        }

        return $row;
    }
}
