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
    public function findByReference(string $reference): ?array
    {
        return $this->find('reference = :value', $reference);
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->find('id = :value', $id);
    }

    /** @return array<string, mixed>|null */
    public function findForAdminDetail(string $idOrReference): ?array
    {
        return $this->findQuery('id = :id_val OR reference = :ref_val', [
            'id_val' => $idOrReference,
            'ref_val' => $idOrReference,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findForUpdate(string $idOrReference): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, reference, confirmation_token_hash, idempotency_key_hash, request_fingerprint, customer_name, phone_number, customer_email, fulfilment_method, delivery_address, state, subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, fulfilment_status, created_at, updated_at '
            . 'FROM orders WHERE id = :id_val OR reference = :ref_val LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'id_val' => $idOrReference,
            'ref_val' => $idOrReference,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Invalid order record.');
        }

        return $this->order($row);
    }

    public function updateFulfilmentStatus(string $id, string $newStatus): void
    {
        $statement = $this->db->prepare('UPDATE orders SET fulfilment_status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute(['id' => $id, 'status' => $newStatus]);
    }

    /**
     * @param array{page: int, per_page: int, status: string|null, search: string|null, sort: string, date_from: string|null, date_to: string|null} $filters
     * @return list<array<string, mixed>>
     */
    public function findForAdminList(array $filters): array
    {
        $conditions = [];
        $params = [];

        $this->applyFilters($filters, $conditions, $params);

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $orderBy = match ($filters['sort']) {
            'oldest' => 'ORDER BY created_at ASC, id ASC',
            'total_high' => 'ORDER BY total_kobo DESC, id DESC',
            'total_low' => 'ORDER BY total_kobo ASC, id ASC',
            default => 'ORDER BY created_at DESC, id DESC',
        };

        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $sql = 'SELECT id, reference, confirmation_token_hash, idempotency_key_hash, request_fingerprint, customer_name, phone_number, customer_email, fulfilment_method, delivery_address, state, subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, fulfilment_status, created_at, updated_at '
            . "FROM orders {$where} {$orderBy} LIMIT :limit OFFSET :offset";

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $statement->bindValue($key, $val);
        }
        $statement->bindValue(':limit', $filters['per_page'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $orders = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid order record.');
            }
            $orders[] = $this->order($row);
        }

        return $orders;
    }

    /**
     * @param array{page: int, per_page: int, status: string|null, search: string|null, sort: string, date_from: string|null, date_to: string|null} $filters
     */
    public function countForAdminList(array $filters): int
    {
        $conditions = [];
        $params = [];

        $this->applyFilters($filters, $conditions, $params);

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql = "SELECT COUNT(*) FROM orders {$where}";

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $statement->bindValue($key, $val);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    public function summaryCounts(): array
    {
        $statement = $this->db->query('SELECT fulfilment_status, COUNT(*) AS count FROM orders GROUP BY fulfilment_status');
        $counts = [
            'new' => 0,
            'confirmed' => 0,
            'processing' => 0,
            'ready' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => 0,
        ];
        if ($statement !== false) {
            $total = 0;
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (is_array($row) && is_string($row['fulfilment_status'] ?? null)) {
                    $status = $row['fulfilment_status'];
                    $rawCount = $row['count'] ?? 0;
                    $count = is_numeric($rawCount) ? (int) $rawCount : 0;
                    $counts[$status] = $count;
                    $total += $count;
                }
            }
            $counts['total'] = $total;
        }

        return $counts;
    }

    /**
     * @param array{page: int, per_page: int, status: string|null, search: string|null, sort: string, date_from: string|null, date_to: string|null} $filters
     * @param list<string> $conditions
     * @param array<string, mixed> $params
     */
    private function applyFilters(array $filters, array &$conditions, array &$params): void
    {
        if ($filters['status'] !== null && $filters['status'] !== '') {
            $conditions[] = 'fulfilment_status = :status';
            $params[':status'] = $filters['status'];
        }

        if ($filters['search'] !== null && $filters['search'] !== '') {
            $conditions[] = '(reference LIKE :search_ref OR customer_name LIKE :search_name OR customer_email LIKE :search_email OR phone_number LIKE :search_phone)';
            $search = '%' . $filters['search'] . '%';
            $params[':search_ref'] = $search;
            $params[':search_name'] = $search;
            $params[':search_email'] = $search;
            $params[':search_phone'] = $search;
        }

        if ($filters['date_from'] !== null && $filters['date_from'] !== '') {
            $conditions[] = 'created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if ($filters['date_to'] !== null && $filters['date_to'] !== '') {
            $conditions[] = 'created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }
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

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function findQuery(string $where, array $params): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, reference, confirmation_token_hash, idempotency_key_hash, request_fingerprint, customer_name, phone_number, customer_email, fulfilment_method, delivery_address, state, subtotal_kobo, delivery_fee_kobo, total_kobo, currency, payment_method, payment_status, fulfilment_status, created_at, updated_at '
            . 'FROM orders WHERE ' . $where . ' LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Invalid order record.');
        }

        return $this->order($row);
    }

    /** @return array<string, mixed>|null */
    private function find(string $where, string $value): ?array
    {
        return $this->findQuery($where, ['value' => $value]);
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function order(array $row): array
    {
        $strings = [
            'id', 'reference', 'confirmation_token_hash',
            'customer_name', 'phone_number', 'fulfilment_method', 'currency', 'payment_method',
            'payment_status', 'fulfilment_status', 'created_at', 'updated_at',
        ];
        foreach ($strings as $field) {
            if (!isset($row[$field]) || !is_string($row[$field])) {
                throw new RuntimeException('Invalid order record.');
            }
        }
        foreach (['idempotency_key_hash', 'request_fingerprint', 'customer_email', 'delivery_address', 'state'] as $field) {
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
