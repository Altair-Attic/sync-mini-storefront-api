<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;

final readonly class RevokedAccessTokenRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function revoke(string $jwtId, string $administratorId, int $expiresAt): void
    {
        $statement = $this->db->prepare('INSERT INTO revoked_access_tokens (jwt_id, merchant_user_id, expires_at, created_at) VALUES (:jwt_id, :merchant_user_id, FROM_UNIXTIME(:expires_at), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)');
        $statement->execute(['jwt_id' => $jwtId, 'merchant_user_id' => $administratorId, 'expires_at' => $expiresAt]);
    }

    public function isRevoked(string $jwtId): bool
    {
        $statement = $this->db->prepare('SELECT EXISTS(SELECT 1 FROM revoked_access_tokens WHERE jwt_id = :jwt_id AND expires_at > UTC_TIMESTAMP())');
        $statement->execute(['jwt_id' => $jwtId]);

        return (bool) $statement->fetchColumn();
    }

    public function cleanupExpired(int $limit = 100): void
    {
        $statement = $this->db->prepare('DELETE FROM revoked_access_tokens WHERE expires_at <= UTC_TIMESTAMP() LIMIT ' . max(1, min($limit, 500)));
        $statement->execute();
    }
}
