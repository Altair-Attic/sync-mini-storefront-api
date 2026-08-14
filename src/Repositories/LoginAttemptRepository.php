<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use ProjectSync\Infrastructure\UuidGenerator;
use RuntimeException;

final readonly class LoginAttemptRepository
{
    public function __construct(private \PDO $db) {}

    /** @return array{identifier_hash: string, attempt_count: int, window_started_at: string, blocked_until: string|null}|null */
    public function find(string $hash): ?array
    {
        $statement = $this->db->prepare('SELECT identifier_hash, attempt_count, window_started_at, blocked_until FROM login_attempts WHERE identifier_hash = :hash');
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (!is_array($row)) throw new RuntimeException('Invalid login attempt record.');
        $identifierHash = $row['identifier_hash'] ?? null; $attemptCount = $row['attempt_count'] ?? null; $windowStartedAt = $row['window_started_at'] ?? null; $blockedUntil = $row['blocked_until'] ?? null;
        if (!is_string($identifierHash) || !is_int($attemptCount) || !is_string($windowStartedAt) || (!is_string($blockedUntil) && $blockedUntil !== null)) throw new RuntimeException('Invalid login attempt record.');
        return ['identifier_hash' => $identifierHash, 'attempt_count' => $attemptCount, 'window_started_at' => $windowStartedAt, 'blocked_until' => $blockedUntil];
    }

    public function save(string $hash, int $count, \DateTimeImmutable $started, ?\DateTimeImmutable $blocked): void { $statement = $this->db->prepare('INSERT INTO login_attempts (id,identifier_hash,attempt_count,window_started_at,blocked_until,created_at,updated_at) VALUES (:id,:hash,:count,:started,:blocked,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE attempt_count=:updated_count,window_started_at=:updated_started,blocked_until=:updated_blocked,updated_at=UTC_TIMESTAMP()'); $startedAt = $started->format('Y-m-d H:i:s'); $blockedUntil = $blocked?->format('Y-m-d H:i:s'); $statement->execute(['id' => UuidGenerator::v4(), 'hash' => $hash, 'count' => $count, 'started' => $startedAt, 'blocked' => $blockedUntil, 'updated_count' => $count, 'updated_started' => $startedAt, 'updated_blocked' => $blockedUntil]); }
    public function clear(string $hash): void { $statement = $this->db->prepare('DELETE FROM login_attempts WHERE identifier_hash = :hash'); $statement->execute(['hash' => $hash]); }
}
