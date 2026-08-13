<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use RuntimeException;

final readonly class CategoryRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array{public_id: string, name: string, slug: string, description: string|null, display_order: int}> */
    public function publicList(): array
    {
        $statement = $this->db->prepare('SELECT public_id, name, slug, description, display_order FROM categories WHERE is_active = TRUE ORDER BY display_order ASC, name ASC');
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->publicRow($row);
        }

        return $result;
    }

    /** @return list<array{id: string, public_id: string, name: string, slug: string, description: string|null, display_order: int, is_active: bool, created_at: string, updated_at: string}> */
    public function adminList(): array
    {
        $statement = $this->db->prepare('SELECT id, public_id, name, slug, description, display_order, is_active, created_at, updated_at FROM categories ORDER BY display_order ASC, name ASC LIMIT 1000');
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->adminRow($row);
        }

        return $result;
    }

    /** @return array{id: string, public_id: string, name: string, slug: string, description: string|null, display_order: int, is_active: bool, created_at: string, updated_at: string}|null */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, public_id, name, slug, description, display_order, is_active, created_at, updated_at FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->adminRow($this->row($row));
    }

    /** @return array{id: string, is_active: bool}|null */
    public function assignmentTarget(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, is_active FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $record = $this->row($row);

        return ['id' => $this->string($record, 'id'), 'is_active' => $this->boolean($record, 'is_active')];
    }

    public function slugExists(string $slug, ?string $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE slug = :slug';
        $parameters = ['slug' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /** @param array{name: string, slug: string, description: string|null, display_order: int, is_active: bool} $category */
    public function create(string $id, string $publicId, array $category): void
    {
        $statement = $this->db->prepare('INSERT INTO categories (id, public_id, name, slug, description, display_order, is_active, created_at, updated_at) VALUES (:id, :public_id, :name, :slug, :description, :display_order, :is_active, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $statement->execute(['id' => $id, 'public_id' => $publicId] + $this->parameters($category));
    }

    /** @param array{name: string, slug: string, description: string|null, display_order: int, is_active: bool} $category */
    public function update(string $id, array $category): void
    {
        $statement = $this->db->prepare('UPDATE categories SET name = :name, slug = :slug, description = :description, display_order = :display_order, is_active = :is_active, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute($this->parameters($category) + ['id' => $id]);
    }

    public function deactivate(string $id): void
    {
        $statement = $this->db->prepare('UPDATE categories SET is_active = FALSE, updated_at = IF(is_active = TRUE, UTC_TIMESTAMP(), updated_at) WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @param array{name: string, slug: string, description: string|null, display_order: int, is_active: bool} $category
     * @return array{name: string, slug: string, description: string|null, display_order: int, is_active: int}
     */
    private function parameters(array $category): array
    {
        return [
            'name' => $category['name'],
            'slug' => $category['slug'],
            'description' => $category['description'],
            'display_order' => $category['display_order'],
            'is_active' => $category['is_active'] ? 1 : 0,
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array{public_id: string, name: string, slug: string, description: string|null, display_order: int}
     */
    private function publicRow(array $row): array
    {
        return [
            'public_id' => $this->string($row, 'public_id'),
            'name' => $this->string($row, 'name'),
            'slug' => $this->string($row, 'slug'),
            'description' => $this->nullable($row, 'description'),
            'display_order' => $this->integer($row, 'display_order'),
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array{id: string, public_id: string, name: string, slug: string, description: string|null, display_order: int, is_active: bool, created_at: string, updated_at: string}
     */
    private function adminRow(array $row): array
    {
        return $this->publicRow($row) + [
            'id' => $this->string($row, 'id'),
            'is_active' => $this->boolean($row, 'is_active'),
            'created_at' => $this->string($row, 'created_at'),
            'updated_at' => $this->string($row, 'updated_at'),
        ];
    }

    /**
     * @param mixed $row
     * @return array<array-key, mixed>
     */
    private function row(mixed $row): array
    {
        if (!is_array($row)) {
            throw new RuntimeException('Invalid category record.');
        }

        return $row;
    }

    /** @param array<array-key, mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Invalid category record.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function nullable(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('Invalid category record.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Invalid category record.');
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $row */
    private function boolean(array $row, string $field): bool
    {
        $value = $this->integer($row, $field);
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid category record.');
        }

        return $value === 1;
    }
}
