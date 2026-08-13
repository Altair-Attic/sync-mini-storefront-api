<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use ProjectSync\Exceptions\InvalidCategoryException;
use ProjectSync\Exceptions\ProductNotFoundException;
use ProjectSync\Exceptions\ProductSlugConflictException;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\CategoryRepository;
use ProjectSync\Repositories\ProductRepository;
use ProjectSync\Validators\ProductListQueryValidator;
use ProjectSync\Validators\ProductValidator;
use PDOException;

final readonly class ProductService
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
        private ProductValidator $validator,
        private ProductListQueryValidator $queryValidator,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    public function publicList(array $query): array
    {
        $validated = $this->queryValidator->publicQuery($query);
        $result = $this->products->publicList($validated);

        return $this->page($result['items'], $validated['page'], $validated['per_page'], $result['total']);
    }

    /** @return array<string, mixed> */
    public function publicDetail(string $slug): array
    {
        $product = $this->products->findPublicBySlug($slug);
        if ($product === null) {
            throw new ProductNotFoundException();
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    public function adminList(array $query): array
    {
        $validated = $this->queryValidator->adminQuery($query);
        $result = $this->products->adminList($validated);
        $items = array_map(fn (array $product): array => $this->adminResponse($product), $result['items']);

        return $this->page($items, $validated['page'], $validated['per_page'], $result['total']);
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        return $this->adminResponse($this->requireProduct($id));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $product = $this->validator->validate($input);
        $this->requireActiveCategory($product['category_id']);
        if ($this->products->slugExists($product['slug'])) {
            throw new ProductSlugConflictException();
        }
        $id = UuidGenerator::v4();
        try {
            $this->products->create($id, UuidGenerator::v4(), $product);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ProductSlugConflictException('Product slug conflict.', 0, $exception);
            }
            throw $exception;
        }

        return $this->find($id);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input): array
    {
        $current = $this->requireProduct($id);
        $product = $this->validator->validate($input);
        $currentCategory = $current['category_id'] ?? null;
        if ($product['category_id'] !== $currentCategory) {
            $this->requireActiveCategory($product['category_id']);
        }
        if ($this->products->slugExists($product['slug'], $id)) {
            throw new ProductSlugConflictException();
        }
        try {
            $this->products->update($id, $product);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ProductSlugConflictException('Product slug conflict.', 0, $exception);
            }
            throw $exception;
        }

        return $this->find($id);
    }

    /** @return array<string, mixed> */
    public function deactivate(string $id): array
    {
        $this->requireProduct($id);
        $this->products->deactivate($id);

        return $this->find($id);
    }

    /** @return array<string, mixed> */
    private function requireProduct(string $id): array
    {
        $product = $this->products->find($id);
        if ($product === null) {
            throw new ProductNotFoundException();
        }

        return $product;
    }

    private function requireActiveCategory(?string $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }
        $category = $this->categories->assignmentTarget($categoryId);
        if ($category === null || !$category['is_active']) {
            throw new InvalidCategoryException();
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    private function page(array $items, int $page, int $perPage, int $total): array
    {
        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }

    /** @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function adminResponse(array $product): array
    {
        foreach (['created_at', 'updated_at'] as $field) {
            $value = $product[$field] ?? null;
            if (!is_string($value)) {
                throw new \RuntimeException('Invalid product timestamp.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
            if ($date === false) {
                throw new \RuntimeException('Invalid product timestamp.');
            }
            $product[$field] = $date->format('Y-m-d\TH:i:s\Z');
        }

        return $product;
    }
}
