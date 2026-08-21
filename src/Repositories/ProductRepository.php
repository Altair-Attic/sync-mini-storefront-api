<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use PDO;
use PDOStatement;
use RuntimeException;

final readonly class ProductRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{category: string|null, page: int, per_page: int, sort: string} $query
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function publicList(array $query): array
    {
        $where = ['p.is_active = TRUE'];
        $parameters = [];
        if ($query['category'] !== null) {
            $where[] = 'c.slug = :category_slug AND c.is_active = TRUE';
            $parameters['category_slug'] = $query['category'];
        }
        $joins = ' LEFT JOIN categories c ON c.id = p.category_id';
        $count = $this->count('products p' . $joins, $where, $parameters);
        $sql = 'SELECT p.public_id, p.slug, p.title, p.description, p.price_kobo, p.is_available, p.stock_quantity, p.image_url, p.display_order, '
            . '(SELECT currency FROM business_profiles LIMIT 1) AS currency, '
            . 'CASE WHEN c.is_active = TRUE THEN c.public_id ELSE NULL END AS category_public_id, '
            . 'CASE WHEN c.is_active = TRUE THEN c.name ELSE NULL END AS category_name, '
            . 'CASE WHEN c.is_active = TRUE THEN c.slug ELSE NULL END AS category_slug '
            . 'FROM products p' . $joins . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $this->orderBy($query['sort']) . ' LIMIT :limit OFFSET :offset';
        $statement = $this->db->prepare($sql);
        $this->executePage($statement, $parameters, $query['per_page'], ($query['page'] - 1) * $query['per_page']);

        return ['items' => $this->rows($statement, true), 'total' => $count];
    }

    /** @return array<string, mixed>|null */
    public function findPublicBySlug(string $slug): ?array
    {
        $statement = $this->db->prepare(
            'SELECT p.public_id, p.slug, p.title, p.description, p.price_kobo, p.is_available, p.stock_quantity, p.image_url, p.display_order, '
            . '(SELECT currency FROM business_profiles LIMIT 1) AS currency, '
            . 'CASE WHEN c.is_active = TRUE THEN c.public_id ELSE NULL END AS category_public_id, '
            . 'CASE WHEN c.is_active = TRUE THEN c.name ELSE NULL END AS category_name, '
            . 'CASE WHEN c.is_active = TRUE THEN c.slug ELSE NULL END AS category_slug '
            . 'FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.slug = :slug AND p.is_active = TRUE LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->publicRow($this->row($row));
    }

    /**
     * Loads all checkout products in one bounded query, including inactive rows so
     * the service can reject the entire cart without disclosing which condition failed.
     *
     * @param list<string> $publicIds
     * @return list<array{id: string, public_id: string, slug: string, title: string, price_kobo: int, is_active: bool, is_available: bool, stock_quantity: int}>
     */
    public function findForCheckout(array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }
        $placeholders = [];
        $parameters = [];
        foreach ($publicIds as $index => $publicId) {
            $name = 'public_id_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $publicId;
        }
        $statement = $this->db->prepare(
            'SELECT id, public_id, slug, title, price_kobo, is_active, is_available, stock_quantity FROM products WHERE public_id IN ('
            . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);
        $products = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid checkout product record.');
            }
            $products[] = [
                'id' => $this->string($row, 'id'),
                'public_id' => $this->string($row, 'public_id'),
                'slug' => $this->string($row, 'slug'),
                'title' => $this->string($row, 'title'),
                'price_kobo' => $this->integer($row, 'price_kobo'),
                'is_active' => $this->boolean($row, 'is_active'),
                'is_available' => $this->boolean($row, 'is_available'),
                'stock_quantity' => $this->integer($row, 'stock_quantity'),
            ];
        }

        return $products;
    }

    /**
     * @param array{category_id: string|null, status: string, availability: string, search: string|null, page: int, per_page: int, sort: string} $query
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(array $query): array
    {
        $where = ['1 = 1'];
        $parameters = [];
        if ($query['category_id'] !== null) {
            $where[] = 'p.category_id = :category_id';
            $parameters['category_id'] = $query['category_id'];
        }
        if ($query['status'] !== 'all') {
            $where[] = 'p.is_active = :is_active';
            $parameters['is_active'] = $query['status'] === 'active' ? 1 : 0;
        }
        if ($query['availability'] === 'available') {
            $where[] = 'p.is_available = TRUE AND p.stock_quantity > 0';
        } elseif ($query['availability'] === 'unavailable') {
            $where[] = '(p.is_available = FALSE OR p.stock_quantity = 0)';
        }
        if ($query['search'] !== null) {
            $where[] = '(p.title LIKE :search OR p.slug LIKE :search)';
            $parameters['search'] = '%' . $query['search'] . '%';
        }
        $joins = ' LEFT JOIN categories c ON c.id = p.category_id';
        $count = $this->count('products p' . $joins, $where, $parameters);
        $statement = $this->db->prepare(
            'SELECT p.id, p.public_id, p.category_id, p.slug, p.title, p.description, p.price_kobo, p.image_url, p.is_active, p.is_available, p.stock_quantity, p.display_order, p.created_at, p.updated_at, '
            . 'c.public_id AS category_public_id, c.name AS category_name, c.slug AS category_slug, c.is_active AS category_is_active '
            . 'FROM products p' . $joins . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $this->orderBy($query['sort']) . ' LIMIT :limit OFFSET :offset'
        );
        $this->executePage($statement, $parameters, $query['per_page'], ($query['page'] - 1) * $query['per_page']);

        return ['items' => $this->rows($statement, false), 'total' => $count];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT p.id, p.public_id, p.category_id, p.slug, p.title, p.description, p.price_kobo, p.image_url, p.is_active, p.is_available, p.stock_quantity, p.display_order, p.created_at, p.updated_at, '
            . 'c.public_id AS category_public_id, c.name AS category_name, c.slug AS category_slug, c.is_active AS category_is_active '
            . 'FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->adminRow($this->row($row));
    }

    public function slugExists(string $slug, ?string $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE slug = :slug';
        $parameters = ['slug' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $exceptId;
        }
        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /** @param array{category_id: string|null, slug: string, title: string, description: string|null, price_kobo: int, image_url: string|null, is_active: bool, is_available: bool, stock_quantity: int, display_order: int} $product */
    public function create(string $id, string $publicId, array $product): void
    {
        $statement = $this->db->prepare('INSERT INTO products (id, public_id, category_id, slug, title, description, price_kobo, image_url, is_active, is_available, stock_quantity, display_order, created_at, updated_at) VALUES (:id, :public_id, :category_id, :slug, :title, :description, :price_kobo, :image_url, :is_active, :is_available, :stock_quantity, :display_order, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $statement->execute(['id' => $id, 'public_id' => $publicId] + $this->parameters($product));
    }

    /** @param array{category_id: string|null, slug: string, title: string, description: string|null, price_kobo: int, image_url: string|null, is_active: bool, is_available: bool, stock_quantity: int, display_order: int} $product */
    public function update(string $id, array $product): void
    {
        $statement = $this->db->prepare('UPDATE products SET category_id = :category_id, slug = :slug, title = :title, description = :description, price_kobo = :price_kobo, image_url = :image_url, is_active = :is_active, is_available = :is_available, stock_quantity = :stock_quantity, display_order = :display_order, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute($this->parameters($product) + ['id' => $id]);
    }

    public function updateAvailability(string $id, bool $isAvailable): void
    {
        $statement = $this->db->prepare('UPDATE products SET is_available = :is_available, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute(['is_available' => $isAvailable ? 1 : 0, 'id' => $id]);
    }

    public function updateImage(string $id, string $imageUrl): void
    {
        $statement = $this->db->prepare('UPDATE products SET image_url = :image_url, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute(['image_url' => $imageUrl, 'id' => $id]);
    }

    /**
     * Atomically reserves stock while the checkout transaction is open.
     *
     * The conditional update is deliberate: a stock check performed before the
     * transaction is only advisory and cannot prevent two concurrent checkouts
     * from both seeing the same remaining quantity.
     */
    public function decrementStock(string $id, int $quantity): bool
    {
        $statement = $this->db->prepare(
            'UPDATE products SET stock_quantity = stock_quantity - :quantity, updated_at = UTC_TIMESTAMP() '
            . 'WHERE id = :id AND is_active = TRUE AND is_available = TRUE AND stock_quantity >= :quantity'
        );
        $statement->execute(['id' => $id, 'quantity' => $quantity]);

        return $statement->rowCount() === 1;
    }

    public function deactivate(string $id): void
    {
        $statement = $this->db->prepare('UPDATE products SET is_active = FALSE, updated_at = IF(is_active = TRUE, UTC_TIMESTAMP(), updated_at) WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function isReferencedByOrder(string $id): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM order_items WHERE product_id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    public function delete(string $id): void
    {
        $statement = $this->db->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @param array{category_id: string|null, slug: string, title: string, description: string|null, price_kobo: int, image_url: string|null, is_active: bool, is_available: bool, stock_quantity: int, display_order: int} $product
     * @return array<string, int|string|null>
     */
    private function parameters(array $product): array
    {
        return [
            'category_id' => $product['category_id'],
            'slug' => $product['slug'],
            'title' => $product['title'],
            'description' => $product['description'],
            'price_kobo' => $product['price_kobo'],
            'image_url' => $product['image_url'],
            'is_active' => $product['is_active'] ? 1 : 0,
            'is_available' => $product['is_available'] ? 1 : 0,
            'stock_quantity' => $product['stock_quantity'],
            'display_order' => $product['display_order'],
        ];
    }

    /**
     * @param list<string> $where
     * @param array<string, int|string> $parameters
     */
    private function count(string $from, array $where, array $parameters): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . implode(' AND ', $where));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, int|string> $parameters */
    private function executePage(PDOStatement $statement, array $parameters, int $limit, int $offset): void
    {
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
    }

    private function orderBy(string $sort): string
    {
        return match ($sort) {
            'title' => 'p.title ASC, p.id ASC',
            'price_low' => 'p.price_kobo ASC, p.title ASC',
            'price_high' => 'p.price_kobo DESC, p.title ASC',
            'newest' => 'p.created_at DESC, p.id DESC',
            default => 'p.display_order ASC, p.title ASC',
        };
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDOStatement $statement, bool $public): array
    {
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid product record.');
            }
            $result[] = $public ? $this->publicRow($row) : $this->adminRow($row);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $categoryPublicId = $this->nullable($row, 'category_public_id');

        return [
            'public_id' => $this->string($row, 'public_id'),
            'slug' => $this->string($row, 'slug'),
            'title' => $this->string($row, 'title'),
            'description' => $this->nullable($row, 'description'),
            'price_kobo' => $this->integer($row, 'price_kobo'),
            'currency' => $this->string($row, 'currency'),
            'image_url' => $this->nullable($row, 'image_url'),
            'is_available' => $this->boolean($row, 'is_available') && $this->integer($row, 'stock_quantity') > 0,
            'stock_quantity' => $this->integer($row, 'stock_quantity'),
            'display_order' => $this->integer($row, 'display_order'),
            'category' => $categoryPublicId === null ? null : [
                'public_id' => $categoryPublicId,
                'name' => $this->string($row, 'category_name'),
                'slug' => $this->string($row, 'category_slug'),
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function adminRow(array $row): array
    {
        $categoryId = $this->nullable($row, 'category_id');

        return [
            'id' => $this->string($row, 'id'),
            'public_id' => $this->string($row, 'public_id'),
            'category_id' => $categoryId,
            'slug' => $this->string($row, 'slug'),
            'title' => $this->string($row, 'title'),
            'description' => $this->nullable($row, 'description'),
            'price_kobo' => $this->integer($row, 'price_kobo'),
            'image_url' => $this->nullable($row, 'image_url'),
            'is_active' => $this->boolean($row, 'is_active'),
            'is_available' => $this->boolean($row, 'is_available') && $this->integer($row, 'stock_quantity') > 0,
            'stock_quantity' => $this->integer($row, 'stock_quantity'),
            'display_order' => $this->integer($row, 'display_order'),
            'category' => $categoryId === null ? null : [
                'public_id' => $this->nullable($row, 'category_public_id'),
                'name' => $this->nullable($row, 'category_name'),
                'slug' => $this->nullable($row, 'category_slug'),
                'is_active' => $this->nullableBoolean($row, 'category_is_active'),
            ],
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
            throw new RuntimeException('Invalid product record.');
        }

        return $row;
    }

    /** @param array<array-key, mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Invalid product record.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function nullable(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('Invalid product record.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Invalid product record.');
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $row */
    private function boolean(array $row, string $field): bool
    {
        $value = $this->integer($row, $field);
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid product record.');
        }

        return $value === 1;
    }

    /** @param array<array-key, mixed> $row */
    private function nullableBoolean(array $row, string $field): ?bool
    {
        return ($row[$field] ?? null) === null ? null : $this->boolean($row, $field);
    }
}
