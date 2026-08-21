<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;

final class CatalogueMigrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($root);
        self::assertSame('1', $_ENV['RUN_DB_INTEGRATION_TESTS'] ?? null);
        $database = require $root . '/config/database.php';
        self::assertStringEndsWith('_test', $database['database']);
        $this->db = (new DatabaseConnection(new Config([
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
        ])))->connect();
        (new MigrationRunner($this->db, $root . '/database/migrations'))->run();
    }

    protected function tearDown(): void
    {
        $this->db->prepare("DELETE FROM products WHERE slug LIKE 'catalogue-test-%'")->execute();
        $this->db->prepare("DELETE FROM categories WHERE slug LIKE 'catalogue-test-%'")->execute();
    }

    public function testMigrationsArePresentAndSecondRunAppliesNothing(): void
    {
        $runner = new MigrationRunner($this->db, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $runner->run());
        $statement = $this->db->prepare('SELECT migration FROM schema_migrations WHERE migration IN (:category, :product, :availability, :stock) ORDER BY migration');
        $statement->execute([
            'category' => '202608130005_create_categories_table',
            'product' => '202608130006_create_products_table',
            'availability' => '202608170015_add_is_available_to_products',
            'stock' => '202608210019_add_stock_quantity_to_products',
        ]);
        self::assertSame([
            '202608130005_create_categories_table',
            '202608130006_create_products_table',
            '202608170015_add_is_available_to_products',
            '202608210019_add_stock_quantity_to_products',
        ], $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testUniqueSlugsPublicIdsIndexesAndForeignKeyBehavior(): void
    {
        $categoryId = UuidGenerator::v4();
        $productId = UuidGenerator::v4();
        $this->insertCategory($categoryId, UuidGenerator::v4(), 'catalogue-test-category');
        $this->insertProduct($productId, UuidGenerator::v4(), $categoryId, 'catalogue-test-product');

        $this->db->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $categoryId]);
        $category = $this->db->prepare('SELECT category_id FROM products WHERE id = :id');
        $category->execute(['id' => $productId]);
        self::assertNull($category->fetchColumn());

        $indexes = $this->db->prepare("SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('categories', 'products')");
        $indexes->execute();
        $names = array_column($indexes->fetchAll(PDO::FETCH_ASSOC), 'INDEX_NAME');
        foreach (['uq_categories_public_id', 'uq_categories_slug', 'idx_categories_active_order_name', 'uq_products_public_id', 'uq_products_slug', 'idx_products_public_listing', 'idx_products_category_listing', 'idx_products_admin_status', 'idx_products_admin_availability'] as $name) {
            self::assertContains($name, $names);
        }

        $columns = $this->db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'");
        $columns->execute();
        $columnNames = array_column($columns->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
        self::assertContains('is_available', $columnNames);
        self::assertContains('stock_quantity', $columnNames);
    }

    public function testUniqueCategoryAndProductValuesAreEnforced(): void
    {
        $categoryPublicId = UuidGenerator::v4();
        $this->insertCategory(UuidGenerator::v4(), $categoryPublicId, 'catalogue-test-unique-category');
        $this->assertDuplicate(fn () => $this->insertCategory(UuidGenerator::v4(), UuidGenerator::v4(), 'catalogue-test-unique-category'));
        $this->assertDuplicate(fn () => $this->insertCategory(UuidGenerator::v4(), $categoryPublicId, 'catalogue-test-other-category'));

        $productPublicId = UuidGenerator::v4();
        $this->insertProduct(UuidGenerator::v4(), $productPublicId, null, 'catalogue-test-unique-product');
        $this->assertDuplicate(fn () => $this->insertProduct(UuidGenerator::v4(), UuidGenerator::v4(), null, 'catalogue-test-unique-product'));
        $this->assertDuplicate(fn () => $this->insertProduct(UuidGenerator::v4(), $productPublicId, null, 'catalogue-test-other-product'));
    }

    private function insertCategory(string $id, string $publicId, string $slug): void
    {
        $statement = $this->db->prepare('INSERT INTO categories (id, public_id, name, slug, created_at, updated_at) VALUES (:id, :public_id, :name, :slug, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $statement->execute(['id' => $id, 'public_id' => $publicId, 'name' => 'Test Category', 'slug' => $slug]);
    }

    private function insertProduct(string $id, string $publicId, ?string $categoryId, string $slug): void
    {
        $statement = $this->db->prepare('INSERT INTO products (id, public_id, category_id, slug, title, price_kobo, created_at, updated_at) VALUES (:id, :public_id, :category_id, :slug, :title, :price_kobo, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $statement->execute(['id' => $id, 'public_id' => $publicId, 'category_id' => $categoryId, 'slug' => $slug, 'title' => 'Test Product', 'price_kobo' => 100]);
    }

    /** @param callable(): void $operation */
    private function assertDuplicate(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a unique constraint violation.');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
        }
    }
}
