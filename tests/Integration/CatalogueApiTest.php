<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\AppFactory;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\CategoryRepository;
use ProjectSync\Repositories\ProductRepository;
use ProjectSync\Services\CategoryService;
use ProjectSync\Services\ProductService;
use ProjectSync\Validators\CategoryValidator;
use ProjectSync\Validators\ProductListQueryValidator;
use ProjectSync\Validators\ProductValidator;

final class CatalogueApiTest extends TestCase
{
    private string $root;
    private PDO $db;
    private CategoryService $categories;
    private ProductService $products;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($this->root);
        $database = require $this->root . '/config/database.php';
        self::assertStringEndsWith('_test', $database['database']);
        $this->db = (new DatabaseConnection(new Config([
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
        ])))->connect();
        (new MigrationRunner($this->db, $this->root . '/database/migrations'))->run();
        $categoryRepository = new CategoryRepository($this->db);
        $this->categories = new CategoryService($categoryRepository, new CategoryValidator());
        $this->products = new ProductService(
            new ProductRepository($this->db),
            $categoryRepository,
            new ProductValidator(),
            new ProductListQueryValidator(),
        );
        $this->ensureBusinessProfile();
    }

    protected function tearDown(): void
    {
        $this->db->prepare("DELETE FROM products WHERE slug LIKE 'catalogue-api-%'")->execute();
        $this->db->prepare("DELETE FROM categories WHERE slug LIKE 'catalogue-api-%'")->execute();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        session_id('');
    }

    public function testPublicCatalogueHidesInactiveRecordsAndInactiveCategoryNavigation(): void
    {
        $category = $this->categories->create(['name' => 'Catalogue API Category', 'slug' => 'catalogue-api-category']);
        $categoryId = $this->stringField($category, 'id');
        $product = $this->products->create([
            'category_id' => $categoryId,
            'title' => 'Catalogue API Product',
            'slug' => 'catalogue-api-product',
            'price_kobo' => 250000,
        ]);

        $list = $this->request('GET', '/api/v1/products?sort=price_high&page=1&per_page=1');
        self::assertSame(200, $list->status);
        self::assertSame(1, $this->responseObject($list, 'meta')['per_page'] ?? null);
        $listed = $this->firstData($list);
        self::assertSame('catalogue-api-product', $listed['slug'] ?? null);
        self::assertArrayNotHasKey('id', $listed);
        self::assertArrayNotHasKey('is_active', $listed);
        self::assertSame('NGN', $listed['currency'] ?? null);

        $this->categories->deactivate($categoryId);
        $detail = $this->request('GET', '/api/v1/products/catalogue-api-product');
        self::assertSame(200, $detail->status);
        self::assertNull($this->responseObject($detail, 'data')['category'] ?? null);
        $filtered = $this->request('GET', '/api/v1/products?category=catalogue-api-category');
        self::assertSame([], $filtered->body['data'] ?? null);
        self::assertSame(0, $this->responseObject($filtered, 'meta')['total'] ?? null);

        $productId = $this->stringField($product, 'id');
        $this->products->deactivate($productId);
        $this->products->deactivate($productId);
        $hidden = $this->request('GET', '/api/v1/products/catalogue-api-product');
        self::assertSame(404, $hidden->status);
        self::assertSame('PRODUCT_NOT_FOUND', $this->responseObject($hidden, 'error')['code'] ?? null);
    }

    public function testPublicCategoryListAndAdminRoutesEnforceAuthentication(): void
    {
        $category = $this->categories->create(['name' => 'Catalogue API Public', 'slug' => 'catalogue-api-public', 'display_order' => 3]);
        $public = $this->request('GET', '/api/v1/categories');
        self::assertSame(200, $public->status);
        $matching = array_values(array_filter($this->dataList($public), static fn (array $item): bool => ($item['slug'] ?? null) === 'catalogue-api-public'));
        self::assertCount(1, $matching);
        self::assertSame(['public_id', 'name', 'slug', 'description', 'display_order'], array_keys($matching[0]));

        foreach (['/api/v1/admin/categories', '/api/v1/admin/products'] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(401, $response->status);
            self::assertSame('UNAUTHENTICATED', $this->responseObject($response, 'error')['code'] ?? null);
        }

        $this->categories->deactivate($this->stringField($category, 'id'));
        $hidden = $this->request('GET', '/api/v1/categories');
        $slugs = array_column($this->dataList($hidden), 'slug');
        self::assertNotContains('catalogue-api-public', $slugs);
    }

    private function request(string $method, string $uri): HttpResponse
    {
        return AppFactory::create($this->root)->handle(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri]);
    }

    /** @return array<string, mixed> */
    private function responseObject(HttpResponse $response, string $field): array
    {
        $value = $response->body[$field] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            self::fail('Response ' . $field . ' must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Response ' . $field . ' must have string fields.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function firstData(HttpResponse $response): array
    {
        $data = $this->dataList($response);
        if ($data === []) {
            self::fail('Expected at least one data item.');
        }

        return $data[0];
    }

    /** @return list<array<string, mixed>> */
    private function dataList(HttpResponse $response): array
    {
        $data = $response->body['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            self::fail('Response data must be a list.');
        }
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                self::fail('Response item must be an object.');
            }
            $record = [];
            foreach ($item as $field => $value) {
                if (!is_string($field)) {
                    self::fail('Response item must have string fields.');
                }
                $record[$field] = $value;
            }
            $items[] = $record;
        }

        return $items;
    }

    /** @param array<string, mixed> $record */
    private function stringField(array $record, string $field): string
    {
        $value = $record[$field] ?? null;
        if (!is_string($value)) {
            self::fail('Expected string field ' . $field . '.');
        }

        return $value;
    }

    private function ensureBusinessProfile(): void
    {
        $count = $this->db->prepare('SELECT COUNT(*) FROM business_profiles');
        $count->execute();
        if ((int) $count->fetchColumn() > 0) {
            return;
        }
        $statement = $this->db->prepare('INSERT INTO business_profiles (id, business_name, slug, domain, whatsapp_number, template_id, currency, timezone, created_at, updated_at) VALUES (:id, :name, :slug, :domain, :phone, :template, :currency, :timezone, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $statement->execute([
            'id' => UuidGenerator::v4(),
            'name' => 'Catalogue Test Store',
            'slug' => 'catalogue-api-test-store',
            'domain' => 'catalogue-api.test',
            'phone' => '+2348030000000',
            'template' => 'classic',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }
}
