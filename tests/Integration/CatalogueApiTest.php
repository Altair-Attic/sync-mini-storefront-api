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
        $this->db->prepare("DELETE FROM merchant_users WHERE email LIKE 'admin-product%'")->execute();
    }

    protected function tearDown(): void
    {
        $this->db->prepare("DELETE FROM products WHERE slug LIKE 'catalogue-api-%'")->execute();
        $this->db->prepare("DELETE FROM categories WHERE slug LIKE 'catalogue-api-%'")->execute();
        $this->db->prepare("DELETE FROM merchant_users WHERE email LIKE 'admin-product%'")->execute();
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

    public function testPublicCatalogueIncludesAvailabilityStateForActiveProducts(): void
    {
        $available = $this->products->create([
            'title' => 'Catalogue API Available Item',
            'slug' => 'catalogue-api-available-item',
            'price_kobo' => 150000,
            'is_available' => true,
        ]);
        $unavailable = $this->products->create([
            'title' => 'Catalogue API Unavailable Item',
            'slug' => 'catalogue-api-unavailable-item',
            'price_kobo' => 200000,
            'is_available' => false,
        ]);

        $list = $this->request('GET', '/api/v1/products?sort=newest&per_page=10');
        self::assertSame(200, $list->status);
        $items = $this->dataList($list);
        $itemMap = [];
        foreach ($items as $item) {
            $itemMap[$this->stringField($item, 'slug')] = $item;
        }

        self::assertArrayHasKey('catalogue-api-available-item', $itemMap);
        self::assertTrue($itemMap['catalogue-api-available-item']['is_available']);

        self::assertArrayHasKey('catalogue-api-unavailable-item', $itemMap);
        self::assertFalse($itemMap['catalogue-api-unavailable-item']['is_available']);

        $detail = $this->request('GET', '/api/v1/products/catalogue-api-unavailable-item');
        self::assertSame(200, $detail->status);
        $detailData = $this->responseObject($detail, 'data');
        self::assertFalse($detailData['is_available']);
    }

    public function testAdminProductEndpointsRequireAuthentication(): void
    {
        $id = UuidGenerator::v4();
        foreach ([
            ['GET', '/api/v1/admin/products'],
            ['POST', '/api/v1/admin/products'],
            ['GET', '/api/v1/admin/products/' . $id],
            ['PUT', '/api/v1/admin/products/' . $id],
            ['PATCH', '/api/v1/admin/products/' . $id . '/availability'],
            ['DELETE', '/api/v1/admin/products/' . $id],
        ] as [$method, $path]) {
            $response = $this->request($method, $path);
            self::assertSame(401, $response->status, 'Expected 401 for ' . $method . ' ' . $path);
            self::assertSame('UNAUTHENTICATED', $this->responseObject($response, 'error')['code'] ?? null);
        }
    }

    public function testAdminProductCrudAvailabilityPatchAndFiltering(): void
    {
        $adminId = UuidGenerator::v4();
        $this->db->prepare('INSERT INTO merchant_users (id, name, email, password_hash, status, created_at, updated_at) VALUES (:id, \'Admin User\', \'admin-product@example.com\', \'$2y$10$dummy\', \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['id' => $adminId]);

        $appConfig = require $this->root . '/config/app.php';
        $jwt = new \ProjectSync\Infrastructure\Auth\JwtService(
            $appConfig['authentication']['jwt_secret'],
            $appConfig['authentication']['jwt_issuer'],
            $appConfig['authentication']['jwt_audience'],
            900,
            30,
            'HS256'
        );
        $token = $jwt->issue($adminId)['access_token'];
        $authHeaders = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];

        $category = $this->categories->create(['name' => 'Catalogue API Admin Cat', 'slug' => 'catalogue-api-admin-cat']);
        $categoryId = $this->stringField($category, 'id');

        // 1. Create product as unavailable
        $this->currentBody = json_encode([
            'category_id' => $categoryId,
            'title' => 'Catalogue API Created Product',
            'slug' => 'catalogue-api-created-product',
            'price_kobo' => 350000,
            'is_active' => true,
            'is_available' => false,
            'display_order' => 5,
        ], JSON_THROW_ON_ERROR);
        $createRes = $this->dispatchProduct('POST', $authHeaders);
        self::assertSame(201, $createRes->status);
        $createdData = $this->responseObject($createRes, 'data');
        $productId = $this->stringField($createdData, 'id');
        self::assertFalse($createdData['is_available']);
        self::assertTrue($createdData['is_active']);
        self::assertSame(350000, $createdData['price_kobo']);

        // 2. Fetch product detail
        $this->currentBody = '';
        $showRes = $this->dispatchProduct('GET', $authHeaders, ['id' => $productId]);
        self::assertSame(200, $showRes->status);
        $showData = $this->responseObject($showRes, 'data');
        self::assertFalse($showData['is_available']);
        $categoryData = is_array($showData['category'] ?? null) ? $showData['category'] : [];
        self::assertSame('Catalogue API Admin Cat', $categoryData['name'] ?? null);

        // 3. Patch availability to true
        $this->currentBody = json_encode(['available' => true], JSON_THROW_ON_ERROR);
        $patchRes = $this->dispatchProduct('PATCH', $authHeaders, ['id' => $productId]);
        self::assertSame(200, $patchRes->status);
        $patchData = $this->responseObject($patchRes, 'data');
        self::assertTrue($patchData['is_available']);

        // 4. Patch availability to false using is_available key
        $this->currentBody = json_encode(['is_available' => false], JSON_THROW_ON_ERROR);
        $patchRes2 = $this->dispatchProduct('PATCH', $authHeaders, ['id' => $productId]);
        self::assertSame(200, $patchRes2->status);
        self::assertFalse($this->responseObject($patchRes2, 'data')['is_available']);

        // 5. Admin listing filter by availability
        $availList = $this->dispatchProduct('GET', $authHeaders + ['REQUEST_URI' => '/api/v1/admin/products?availability=unavailable']);
        self::assertSame(200, $availList->status);
        $availSlugs = array_column($this->dataList($availList), 'slug');
        self::assertContains('catalogue-api-created-product', $availSlugs);

        $availList2 = $this->dispatchProduct('GET', $authHeaders + ['REQUEST_URI' => '/api/v1/admin/products?availability=available']);
        self::assertSame(200, $availList2->status);
        $availSlugs2 = array_column($this->dataList($availList2), 'slug');
        self::assertNotContains('catalogue-api-created-product', $availSlugs2);

        // 6. Update product via PUT
        $this->currentBody = json_encode([
            'category_id' => $categoryId,
            'title' => 'Catalogue API Updated Title',
            'slug' => 'catalogue-api-created-product',
            'price_kobo' => 450000,
            'is_active' => true,
            'is_available' => true,
            'display_order' => 1,
        ], JSON_THROW_ON_ERROR);
        $updateRes = $this->dispatchProduct('PUT', $authHeaders, ['id' => $productId]);
        self::assertSame(200, $updateRes->status);
        $updateData = $this->responseObject($updateRes, 'data');
        self::assertSame('Catalogue API Updated Title', $updateData['title']);
        self::assertSame(450000, $updateData['price_kobo']);
        self::assertTrue($updateData['is_available']);

        // 7. Assigning invalid category fails
        $this->currentBody = json_encode([
            'category_id' => UuidGenerator::v4(),
            'title' => 'Catalogue API Updated Title',
            'slug' => 'catalogue-api-created-product',
            'price_kobo' => 450000,
            'is_active' => true,
            'is_available' => true,
            'display_order' => 1,
        ], JSON_THROW_ON_ERROR);
        $invalidCatRes = $this->dispatchProduct('PUT', $authHeaders, ['id' => $productId]);
        self::assertSame(422, $invalidCatRes->status);
        self::assertSame('INVALID_CATEGORY', $this->responseObject($invalidCatRes, 'error')['code'] ?? null);

        // 8. Delete / deactivation
        $deleteRes = $this->dispatchProduct('DELETE', $authHeaders, ['id' => $productId]);
        self::assertSame(200, $deleteRes->status);
        self::assertFalse($this->responseObject($deleteRes, 'data')['is_active']);

        // Cleanup
        $this->db->prepare('DELETE FROM merchant_users WHERE id = :id')->execute(['id' => $adminId]);
    }

    private string $currentBody = '';

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $route
     */
    private function dispatchProduct(string $method, array $server = [], array $route = []): HttpResponse
    {
        $appConfig = require $this->root . '/config/app.php';
        $jwt = new \ProjectSync\Infrastructure\Auth\JwtService(
            $appConfig['authentication']['jwt_secret'],
            $appConfig['authentication']['jwt_issuer'],
            $appConfig['authentication']['jwt_audience'],
            900,
            30,
            'HS256'
        );
        $authMiddleware = new \ProjectSync\Middleware\AuthenticationMiddleware(
            $jwt,
            new \ProjectSync\Repositories\MerchantUserRepository($this->db),
            new \ProjectSync\Repositories\RevokedAccessTokenRepository($this->db),
        );
        $controller = new \ProjectSync\Controllers\ProductController(
            $this->products,
            $authMiddleware,
            fn (): string => $this->currentBody,
        );

        $requestId = 'req_test_' . substr(UuidGenerator::v4(), 0, 8);
        $server['REQUEST_METHOD'] = $method;

        return match ($method) {
            'GET' => isset($route['id']) ? $controller->show($requestId, $server, $route) : $controller->adminList($requestId, $server, $route),
            'POST' => $controller->create($requestId, $server, $route),
            'PUT' => $controller->update($requestId, $server, $route),
            'PATCH' => $controller->updateAvailability($requestId, $server, $route),
            'DELETE' => $controller->delete($requestId, $server, $route),
            default => throw new \InvalidArgumentException('Unsupported test method: ' . $method),
        };
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
