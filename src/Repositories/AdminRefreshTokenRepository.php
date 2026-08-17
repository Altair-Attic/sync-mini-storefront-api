<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use RuntimeException;

final readonly class AdminRefreshTokenRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function begin(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** @return array{id: string, merchant_user_id: string, family_id: string, expires_at: string, revoked_at: string|null, replaced_by_token_id: string|null}|null */
    public function lockByHash(string $hash): ?array
    {
        $statement = $this->db->prepare('SELECT id, merchant_user_id, family_id, expires_at, revoked_at, replaced_by_token_id FROM admin_refresh_tokens WHERE token_hash = :token_hash LIMIT 1 FOR UPDATE');
        $statement->execute(['token_hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Invalid refresh-token record.');
        }
        $id = $row['id'] ?? null;
        $userId = $row['merchant_user_id'] ?? null;
        $familyId = $row['family_id'] ?? null;
        $expiresAt = $row['expires_at'] ?? null;
        $revokedAt = $row['revoked_at'] ?? null;
        $replacementId = $row['replaced_by_token_id'] ?? null;
        if (!is_string($id) || !is_string($userId) || !is_string($familyId) || !is_string($expiresAt)
            || (!is_string($revokedAt) && $revokedAt !== null)
            || (!is_string($replacementId) && $replacementId !== null)
        ) {
            throw new RuntimeException('Invalid refresh-token record.');
        }

        return [
            'id' => $id,
            'merchant_user_id' => $userId,
            'family_id' => $familyId,
            'expires_at' => $expiresAt,
            'revoked_at' => $revokedAt,
            'replaced_by_token_id' => $replacementId,
        ];
    }

    public function create(string $id, string $userId, string $hash, string $familyId, string $expiresAt): void
    {
        $statement = $this->db->prepare('INSERT INTO admin_refresh_tokens (id, merchant_user_id, token_hash, family_id, expires_at, created_at, updated_at) VALUES (:id, :merchant_user_id, :token_hash, :family_id, :expires_at, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $statement->execute([
            'id' => $id,
            'merchant_user_id' => $userId,
            'token_hash' => $hash,
            'family_id' => $familyId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function markRotated(string $id, string $replacementId): void
    {
        $statement = $this->db->prepare('UPDATE admin_refresh_tokens SET last_used_at = UTC_TIMESTAMP(), revoked_at = UTC_TIMESTAMP(), replaced_by_token_id = :replacement_id, updated_at = UTC_TIMESTAMP() WHERE id = :id AND revoked_at IS NULL');
        $statement->execute(['id' => $id, 'replacement_id' => $replacementId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Refresh token was not rotated.');
        }
    }

    public function revokeFamily(string $familyId): void
    {
        $statement = $this->db->prepare('UPDATE admin_refresh_tokens SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE family_id = :family_id');
        $statement->execute(['family_id' => $familyId]);
    }

    public function revokeAllForAdministrator(string $administratorId): void
    {
        $statement = $this->db->prepare('UPDATE admin_refresh_tokens SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE merchant_user_id = :merchant_user_id');
        $statement->execute(['merchant_user_id' => $administratorId]);
    }

    public function cleanupExpired(int $limit = 100): int
    {
        $bounded = max(1, min($limit, 500));
        $statement = $this->db->prepare('DELETE FROM admin_refresh_tokens WHERE expires_at < UTC_TIMESTAMP() OR revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) LIMIT ' . $bounded);
        $statement->execute();

        return $statement->rowCount();
    }
}
