<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

final readonly class NotificationJobRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(string $id, string $orderId, string $recipientType, string $recipientHash, int $maxAttempts): bool
    {
        try {
            $statement = $this->db->prepare(
                "INSERT INTO notification_jobs (id, order_id, channel, recipient_type, recipient_hash, status, attempts, max_attempts, available_at, created_at, updated_at) "
                . "VALUES (:id, :order_id, 'email', :recipient_type, :recipient_hash, 'pending', 0, :max_attempts, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            );
            $statement->execute([
                'id' => $id, 'order_id' => $orderId, 'recipient_type' => $recipientType,
                'recipient_hash' => $recipientHash, 'max_attempts' => $maxAttempts,
            ]);

            return true;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }
    }

    /** @return array<string, string> */
    public function stateForOrder(string $orderId): array
    {
        $statement = $this->db->prepare('SELECT recipient_type, status FROM notification_jobs WHERE order_id = :order_id');
        $statement->execute(['order_id' => $orderId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['recipient_type'] ?? null) || !is_string($row['status'] ?? null)) {
                throw new RuntimeException('Invalid notification job record.');
            }
            $result[$row['recipient_type']] = $row['status'] === 'sent' ? 'sent' : 'queued';
        }

        return $result;
    }

    public function recoverStale(int $timeoutSeconds): int
    {
        $statement = $this->db->prepare(
            "UPDATE notification_jobs SET status = IF(attempts >= max_attempts, 'failed', 'pending'), processing_started_at = NULL, available_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() "
            . "WHERE status = 'processing' AND processing_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :timeout SECOND)"
        );
        $statement->bindValue(':timeout', $timeoutSeconds, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @return array<string, mixed>|null */
    public function claim(string $id): ?array
    {
        $claim = $this->db->prepare(
            "UPDATE notification_jobs SET status = 'processing', processing_started_at = UTC_TIMESTAMP(), attempts = attempts + 1, last_attempt_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() "
            . "WHERE id = :id AND status = 'pending' AND available_at <= UTC_TIMESTAMP() AND attempts < max_attempts"
        );
        $claim->execute(['id' => $id]);

        return $claim->rowCount() === 1 ? $this->find($id) : null;
    }

    /** @return list<array<string, mixed>> */
    public function claimDue(int $limit): array
    {
        $candidate = $this->db->prepare(
            "SELECT id FROM notification_jobs WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP() AND attempts < max_attempts "
            . 'ORDER BY available_at ASC, created_at ASC LIMIT :limit'
        );
        $candidate->bindValue(':limit', $limit, PDO::PARAM_INT);
        $candidate->execute();
        $claimed = [];
        foreach ($candidate->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!is_string($id)) {
                throw new RuntimeException('Invalid notification job identifier.');
            }
            $job = $this->claim($id);
            if ($job !== null) {
                $claimed[] = $job;
            }
        }

        return $claimed;
    }

    public function markSent(string $id): void
    {
        $statement = $this->db->prepare("UPDATE notification_jobs SET status = 'sent', sent_at = UTC_TIMESTAMP(), processing_started_at = NULL, last_error_code = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'processing'");
        $statement->execute(['id' => $id]);
    }

    public function markFailed(string $id, int $attempts, int $maxAttempts, int $delaySeconds, string $errorCode): void
    {
        $status = $attempts >= $maxAttempts ? 'failed' : 'pending';
        $statement = $this->db->prepare(
            'UPDATE notification_jobs SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND), processing_started_at = NULL, last_error_code = :error_code, updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = \'processing\''
        );
        $statement->bindValue(':status', $status);
        $statement->bindValue(':delay', $delaySeconds, PDO::PARAM_INT);
        $statement->bindValue(':error_code', $errorCode);
        $statement->bindValue(':id', $id);
        $statement->execute();
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, order_id, channel, recipient_type, recipient_hash, status, attempts, max_attempts, available_at, processing_started_at, last_attempt_at, sent_at, last_error_code, created_at, updated_at FROM notification_jobs WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Invalid notification job record.');
        }
        foreach (['id', 'order_id', 'channel', 'recipient_type', 'status', 'available_at', 'created_at', 'updated_at'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new RuntimeException('Invalid notification job record.');
            }
        }
        foreach (['attempts', 'max_attempts'] as $field) {
            $value = $row[$field] ?? null;
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new RuntimeException('Invalid notification job record.');
            }
            $row[$field] = (int) $value;
        }

        return $row;
    }
}
