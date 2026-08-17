<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use PDOException;

final readonly class PaymentEventRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{
     *     id: string,
     *     payment_attempt_id: ?string,
     *     order_id: ?string,
     *     provider: string,
     *     event_type: string,
     *     provider_reference: string,
     *     payload_hash: string,
     *     processing_status: string,
     *     processing_notes?: ?string,
     * } $data
     */
    public function record(array $data): bool
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO payment_events ('
                . 'id, payment_attempt_id, order_id, provider, event_type, provider_reference, '
                . 'payload_hash, processing_status, processing_notes, created_at'
                . ') VALUES ('
                . ':id, :payment_attempt_id, :order_id, :provider, :event_type, :provider_reference, '
                . ':payload_hash, :processing_status, :processing_notes, UTC_TIMESTAMP()'
                . ')'
            );

            $stmt->execute([
                'id' => $data['id'],
                'payment_attempt_id' => $data['payment_attempt_id'],
                'order_id' => $data['order_id'],
                'provider' => $data['provider'],
                'event_type' => $data['event_type'],
                'provider_reference' => $data['provider_reference'],
                'payload_hash' => $data['payload_hash'],
                'processing_status' => $data['processing_status'],
                'processing_notes' => $data['processing_notes'] ?? null,
            ]);

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function existsForProviderReference(string $provider, string $eventType, string $providerReference): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM payment_events WHERE provider = :provider AND event_type = :event_type AND provider_reference = :ref LIMIT 1'
        );
        $stmt->execute([
            'provider' => $provider,
            'event_type' => $eventType,
            'ref' => $providerReference,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByOrderId(string $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, payment_attempt_id, order_id, provider, event_type, provider_reference, '
            . 'payload_hash, processing_status, processing_notes, created_at '
            . 'FROM payment_events WHERE order_id = :order_id ORDER BY created_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $item */
                $item = $row;
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByPaymentAttemptId(string $paymentAttemptId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, payment_attempt_id, order_id, provider, event_type, provider_reference, '
            . 'payload_hash, processing_status, processing_notes, created_at '
            . 'FROM payment_events WHERE payment_attempt_id = :attempt_id ORDER BY created_at DESC'
        );
        $stmt->execute(['attempt_id' => $paymentAttemptId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $item */
                $item = $row;
                $results[] = $item;
            }
        }

        return $results;
    }

}
