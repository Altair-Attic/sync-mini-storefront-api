<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class ProductValidator
{
    /** @var list<string> */
    private const array FIELDS = ['category_id', 'slug', 'title', 'description', 'price_kobo', 'image_url', 'is_active', 'display_order'];

    /** @var list<string> */
    private const array IMMUTABLE = ['id', 'public_id', 'created_at', 'updated_at', 'currency'];

    /**
     * @param array<string, mixed> $input
     * @return array{category_id: string|null, slug: string, title: string, description: string|null, price_kobo: int, image_url: string|null, is_active: bool, display_order: int}
     */
    public function validate(array $input): array
    {
        $errors = [];
        foreach ($input as $field => $_value) {
            if (in_array($field, self::IMMUTABLE, true)) {
                $errors[$field] = ['This field is immutable.'];
            } elseif (!in_array($field, self::FIELDS, true)) {
                $errors[$field] = ['Unknown field.'];
            }
        }

        $title = isset($input['title']) && is_string($input['title']) ? trim($input['title']) : '';
        if (mb_strlen($title) < 2 || mb_strlen($title) > 160) {
            $errors['title'] = ['Use between 2 and 160 characters.'];
        }
        $slugValue = $input['slug'] ?? null;
        $slug = $slugValue === null || (is_string($slugValue) && trim($slugValue) === '')
            ? $this->slugify($title)
            : (is_string($slugValue) ? trim($slugValue) : '');
        if (strlen($slug) > 180 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors['slug'] = ['Use a lowercase URL-safe slug.'];
        }

        $categoryId = $input['category_id'] ?? null;
        if ($categoryId !== null && (!is_string($categoryId) || !$this->uuid($categoryId))) {
            $errors['category_id'] = ['Enter a valid category UUID or null.'];
        }
        $description = $this->nullableString($input['description'] ?? null);
        if ($description === false || (is_string($description) && mb_strlen($description) > 10000)) {
            $errors['description'] = ['Use at most 10,000 characters.'];
        }
        $price = $input['price_kobo'] ?? null;
        if (!is_int($price) || $price < 0) {
            $errors['price_kobo'] = ['Enter a non-negative integer amount in kobo.'];
        }
        $imageUrl = $this->nullableString($input['image_url'] ?? null);
        if ($imageUrl === false || (is_string($imageUrl) && !$this->validImageUrl($imageUrl))) {
            $errors['image_url'] = ['Enter a valid HTTPS URL or managed local image path.'];
        }
        $isActive = $input['is_active'] ?? true;
        if (!is_bool($isActive)) {
            $errors['is_active'] = ['Enter true or false.'];
        }
        $displayOrder = $input['display_order'] ?? 0;
        if (!is_int($displayOrder) || $displayOrder < 0) {
            $errors['display_order'] = ['Enter an integer of at least 0.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'category_id' => is_string($categoryId) ? strtolower($categoryId) : null,
            'slug' => $slug,
            'title' => $title,
            'description' => is_string($description) ? $description : null,
            'price_kobo' => $price,
            'image_url' => is_string($imageUrl) ? $imageUrl : null,
            'is_active' => $isActive,
            'display_order' => $displayOrder,
        ];
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function nullableString(mixed $value): string|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? trim($value) : false;
    }

    private function validImageUrl(string $value): bool
    {
        if (strlen($value) > 2048) {
            return false;
        }
        if (str_starts_with($value, '/') && !str_contains($value, '..')) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false && parse_url($value, PHP_URL_SCHEME) === 'https';
    }

    private function slugify(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = strtolower(is_string($ascii) ? $ascii : $value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized);

        return trim(is_string($slug) ? $slug : '', '-');
    }
}
