<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use RuntimeException;

final readonly class PaymentAttemptRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{
     *     id: string,
     *     order_id: string,
     *     provider: string,
     *     internal_reference: string,
     *     provider_reference: ?string,
     *     access_code: ?string,
     *     authorization_url: ?string,
     *     idempotency_key_hash: string,
     *     expected_amount_kobo: int,
     *     currency: string,
     *     status: string,
     *     resolution_status?: string,
     * } $data
     */
    public function insert(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO payment_attempts ('
            . 'id, order_id, provider, internal_reference, provider_reference, access_code, authorization_url, '
            . 'idempotency_key_hash, expected_amount_kobo, currency, status, resolution_status, initiated_at, created_at, updated_at'
            . ') VALUES ('
            . ':id, :order_id, :provider, :internal_reference, :provider_reference, :access_code, :authorization_url, '
            . ':idempotency_key_hash, :expected_amount_kobo, :currency, :status, :resolution_status, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()'
            . ')'
        );

        $stmt->execute([
            'id' => $data['id'],
            'order_id' => $data['order_id'],
            'provider' => $data['provider'],
            'internal_reference' => $data['internal_reference'],
            'provider_reference' => $data['provider_reference'],
            'access_code' => $data['access_code'],
            'authorization_url' => $data['authorization_url'],
            'idempotency_key_hash' => $data['idempotency_key_hash'],
            'expected_amount_kobo' => $data['expected_amount_kobo'],
            'currency' => $data['currency'],
            'status' => $data['status'],
            'resolution_status' => $data['resolution_status'] ?? 'none',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_id, provider, internal_reference, provider_reference, access_code, authorization_url, '
            . 'idempotency_key_hash, expected_amount_kobo, verified_amount_kobo, currency, status, resolution_status, '
            . 'provider_status, channel, initiated_at, finalized_at, created_at, updated_at '
            . 'FROM payment_attempts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByInternalReference(string $reference): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_id, provider, internal_reference, provider_reference, access_code, authorization_url, '
            . 'idempotency_key_hash, expected_amount_kobo, verified_amount_kobo, currency, status, resolution_status, '
            . 'provider_status, channel, initiated_at, finalized_at, created_at, updated_at '
            . 'FROM payment_attempts WHERE internal_reference = :ref LIMIT 1'
        );
        $stmt->execute(['ref' => $reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderAndIdempotencyHash(string $orderId, string $idempotencyHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_id, provider, internal_reference, provider_reference, access_code, authorization_url, '
            . 'idempotency_key_hash, expected_amount_kobo, verified_amount_kobo, currency, status, resolution_status, '
            . 'provider_status, channel, initiated_at, finalized_at, created_at, updated_at '
            . 'FROM payment_attempts WHERE order_id = :order_id AND idempotency_key_hash = :hash LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId, 'hash' => $idempotencyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByOrderId(string $orderId, int $ttlSeconds = 900): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_id, provider, internal_reference, provider_reference, access_code, authorization_url, '
            . 'idempotency_key_hash, expected_amount_kobo, verified_amount_kobo, currency, status, resolution_status, '
            . 'provider_status, channel, initiated_at, finalized_at, created_at, updated_at '
            . 'FROM payment_attempts '
            . "WHERE order_id = :order_id AND status = 'pending' AND initiated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :ttl SECOND) "
            . 'ORDER BY initiated_at DESC LIMIT 1'
        );
        $stmt->bindValue(':order_id', $orderId);
        $stmt->bindValue(':ttl', $ttlSeconds, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByOrderId(string $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_id, provider, internal_reference, provider_reference, access_code, authorization_url, '
            . 'idempotency_key_hash, expected_amount_kobo, verified_amount_kobo, currency, status, resolution_status, '
            . 'provider_status, channel, initiated_at, finalized_at, created_at, updated_at '
            . 'FROM payment_attempts WHERE order_id = :order_id ORDER BY created_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $results[] = $this->normalizeRow($row);
            }
        }

        return $results;
    }

    public function updateProviderInitialization(
        string $id,
        string $status,
        string $providerReference,
        ?string $accessCode,
        ?string $authorizationUrl,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE payment_attempts SET '
            . 'status = :status, provider_reference = :provider_ref, access_code = :access_code, authorization_url = :auth_url, updated_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'provider_ref' => $providerReference,
            'access_code' => $accessCode,
            'auth_url' => $authorizationUrl,
        ]);
    }

    public function updateStatus(string $id, string $status, ?string $providerStatus = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_attempts SET '
            . 'status = :status, provider_status = :provider_status, updated_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'provider_status' => $providerStatus,
        ]);
    }

    public function finalizeSuccessful(
        string $id,
        int $verifiedAmountKobo,
        ?string $providerStatus,
        ?string $channel,
        string $resolutionStatus = 'none',
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE payment_attempts SET '
            . "status = 'successful', verified_amount_kobo = :verified_amount, provider_status = :provider_status, "
            . 'channel = :channel, resolution_status = :resolution_status, finalized_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'verified_amount' => $verifiedAmountKobo,
            'provider_status' => $providerStatus,
            'channel' => $channel,
            'resolution_status' => $resolutionStatus,
        ]);
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $row;
        $rawExpected = $normalized['expected_amount_kobo'] ?? null;
        if ($rawExpected !== null) {
            $normalized['expected_amount_kobo'] = is_int($rawExpected) ? $rawExpected : (is_numeric($rawExpected) ? (int) $rawExpected : 0);
        }
        $rawVerified = $normalized['verified_amount_kobo'] ?? null;
        if ($rawVerified !== null) {
            $normalized['verified_amount_kobo'] = is_int($rawVerified) ? $rawVerified : (is_numeric($rawVerified) ? (int) $rawVerified : 0);
        }

        return $normalized;
    }


}
