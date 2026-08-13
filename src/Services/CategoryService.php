<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use ProjectSync\Exceptions\CategoryNotFoundException;
use ProjectSync\Exceptions\CategorySlugConflictException;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\CategoryRepository;
use ProjectSync\Validators\CategoryValidator;
use PDOException;

final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $categories,
        private CategoryValidator $validator,
    ) {
    }

    /** @return list<array{public_id: string, name: string, slug: string, description: string|null, display_order: int}> */
    public function publicList(): array
    {
        return $this->categories->publicList();
    }

    /** @return list<array<string, mixed>> */
    public function adminList(): array
    {
        return array_map(fn (array $category): array => $this->adminResponse($category), $this->categories->adminList());
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        return $this->adminResponse($this->requireCategory($id));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $category = $this->validator->validate($input);
        if ($this->categories->slugExists($category['slug'])) {
            throw new CategorySlugConflictException();
        }
        $id = UuidGenerator::v4();
        try {
            $this->categories->create($id, UuidGenerator::v4(), $category);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new CategorySlugConflictException('Category slug conflict.', 0, $exception);
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
        $this->requireCategory($id);
        $category = $this->validator->validate($input);
        if ($this->categories->slugExists($category['slug'], $id)) {
            throw new CategorySlugConflictException();
        }
        try {
            $this->categories->update($id, $category);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new CategorySlugConflictException('Category slug conflict.', 0, $exception);
            }
            throw $exception;
        }

        return $this->find($id);
    }

    /** @return array<string, mixed> */
    public function deactivate(string $id): array
    {
        $this->requireCategory($id);
        $this->categories->deactivate($id);

        return $this->find($id);
    }

    /** @return array{id: string, public_id: string, name: string, slug: string, description: string|null, display_order: int, is_active: bool, created_at: string, updated_at: string} */
    private function requireCategory(string $id): array
    {
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new CategoryNotFoundException();
        }

        return $category;
    }

    /** @param array<string, mixed> $category
     * @return array<string, mixed>
     */
    private function adminResponse(array $category): array
    {
        foreach (['created_at', 'updated_at'] as $field) {
            $value = $category[$field] ?? null;
            if (!is_string($value)) {
                throw new \RuntimeException('Invalid category timestamp.');
            }
            $category[$field] = $this->utc($value);
        }

        return $category;
    }

    private function utc(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \RuntimeException('Invalid category timestamp.');
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }
}
