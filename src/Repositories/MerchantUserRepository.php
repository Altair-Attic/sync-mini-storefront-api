<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use RuntimeException;

final readonly class MerchantUserRepository
{
    public function __construct(private \PDO $db) {}

    /** @return array{id: string, name: string, email: string, password_hash: string, status: string}|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT id, name, email, password_hash, status FROM merchant_users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (!is_array($row)) throw new RuntimeException('Invalid merchant user record.');
        $id = $row['id'] ?? null; $name = $row['name'] ?? null; $email = $row['email'] ?? null; $passwordHash = $row['password_hash'] ?? null; $status = $row['status'] ?? null;
        if (!is_string($id) || !is_string($name) || !is_string($email) || !is_string($passwordHash) || !is_string($status)) throw new RuntimeException('Invalid merchant user record.');
        return ['id' => $id, 'name' => $name, 'email' => $email, 'password_hash' => $passwordHash, 'status' => $status];
    }

    /** @return array{email: string}|null */
    public function first(): ?array
    {
        $statement = $this->db->prepare('SELECT email FROM merchant_users LIMIT 1');
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (!is_array($row)) throw new RuntimeException('Invalid merchant user record.');
        $email = $row['email'] ?? null;
        if (!is_string($email)) throw new RuntimeException('Invalid merchant user record.');

        return ['email' => $email];
    }

    public function create(string $id, string $name, string $email, string $passwordHash): void
    {
        $statement = $this->db->prepare("INSERT INTO merchant_users (id,name,email,password_hash,status,created_at,updated_at) VALUES (:id,:name,:email,:password_hash,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
    }

    /** @return array{id: string, name: string, email: string}|null */
    public function findActive(string $id): ?array
    {
        $statement = $this->db->prepare("SELECT id, name, email FROM merchant_users WHERE id = :id AND status = 'active' LIMIT 1");
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (!is_array($row)) throw new RuntimeException('Invalid merchant user record.');
        $id = $row['id'] ?? null; $name = $row['name'] ?? null; $email = $row['email'] ?? null;
        if (!is_string($id) || !is_string($name) || !is_string($email)) throw new RuntimeException('Invalid merchant user record.');
        return ['id' => $id, 'name' => $name, 'email' => $email];
    }

    public function touchLogin(string $id): void
    {
        $statement = $this->db->prepare('UPDATE merchant_users SET last_login_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
