<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class ProductListQueryValidator
{
    /** @var list<string> */
    private const array SORTS = ['display_order', 'title', 'price_low', 'price_high', 'newest'];

    /**
     * @param array<string, mixed> $query
     * @return array{category: string|null, page: int, per_page: int, sort: string}
     */
    public function publicQuery(array $query): array
    {
        $errors = $this->unknown($query, ['category', 'page', 'per_page', 'sort']);
        $category = $this->optionalString($query['category'] ?? null);
        if ($category === false || (is_string($category) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $category) !== 1)) {
            $errors['category'] = ['Enter a valid category slug.'];
        }
        [$page, $perPage, $sort] = $this->common($query, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['category' => is_string($category) ? $category : null, 'page' => $page, 'per_page' => $perPage, 'sort' => $sort];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{category_id: string|null, status: string, availability: string, search: string|null, page: int, per_page: int, sort: string}
     */
    public function adminQuery(array $query): array
    {
        $errors = $this->unknown($query, ['category_id', 'status', 'availability', 'search', 'page', 'per_page', 'sort']);
        $categoryId = $this->optionalString($query['category_id'] ?? null);
        if ($categoryId === false || (is_string($categoryId) && preg_match('/^[0-9a-f-]{36}$/i', $categoryId) !== 1)) {
            $errors['category_id'] = ['Enter a valid category UUID.'];
        }
        $status = $query['status'] ?? 'all';
        if (!is_string($status) || !in_array($status, ['active', 'inactive', 'all'], true)) {
            $errors['status'] = ['Use active, inactive, or all.'];
        }
        $availability = $query['availability'] ?? 'all';
        if (!is_string($availability) || !in_array($availability, ['available', 'unavailable', 'all'], true)) {
            $errors['availability'] = ['Use available, unavailable, or all.'];
        }
        $search = $this->optionalString($query['search'] ?? null);
        if ($search === false || (is_string($search) && mb_strlen($search) > 160)) {
            $errors['search'] = ['Use at most 160 characters.'];
        }
        [$page, $perPage, $sort] = $this->common($query, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'category_id' => is_string($categoryId) ? strtolower($categoryId) : null,
            'status' => is_string($status) ? $status : 'all',
            'availability' => is_string($availability) ? $availability : 'all',
            'search' => is_string($search) ? $search : null,
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, list<string>> $errors
     * @return array{int, int, string}
     */
    private function common(array $query, array &$errors): array
    {
        $page = $this->positiveInteger($query['page'] ?? '1');
        if ($page === null) {
            $errors['page'] = ['Enter a positive integer.'];
            $page = 1;
        }
        $perPage = $this->positiveInteger($query['per_page'] ?? '20');
        if ($perPage === null || $perPage > 100) {
            $errors['per_page'] = ['Enter an integer between 1 and 100.'];
            $perPage = 20;
        }
        $sort = $query['sort'] ?? 'display_order';
        if (!is_string($sort) || !in_array($sort, self::SORTS, true)) {
            $errors['sort'] = ['Choose a supported sorting mode.'];
            $sort = 'display_order';
        }

        return [$page, $perPage, $sort];
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $allowed
     * @return array<string, list<string>>
     */
    private function unknown(array $query, array $allowed): array
    {
        $errors = [];
        foreach ($query as $field => $_value) {
            if (!in_array($field, $allowed, true)) {
                $errors[$field] = ['Unknown filter.'];
            }
        }

        return $errors;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function optionalString(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : false;
    }
}
