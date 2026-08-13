<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class CategoryValidator
{
    /** @var list<string> */
    private const array FIELDS = ['name', 'slug', 'description', 'display_order', 'is_active'];

    /** @var list<string> */
    private const array IMMUTABLE = ['id', 'public_id', 'created_at', 'updated_at'];

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, slug: string, description: string|null, display_order: int, is_active: bool}
     */
    public function validate(array $input): array
    {
        $errors = $this->unknownFields($input);
        $name = isset($input['name']) && is_string($input['name']) ? trim($input['name']) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = ['Use between 2 and 100 characters.'];
        }

        $slugValue = $input['slug'] ?? null;
        $slug = $slugValue === null || (is_string($slugValue) && trim($slugValue) === '')
            ? $this->slugify($name)
            : (is_string($slugValue) ? trim($slugValue) : '');
        if (strlen($slug) > 120 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors['slug'] = ['Use a lowercase URL-safe slug.'];
        }

        $description = $this->nullableString($input['description'] ?? null);
        if ($description === false || (is_string($description) && mb_strlen($description) > 1000)) {
            $errors['description'] = ['Use at most 1,000 characters.'];
        }

        $displayOrder = $input['display_order'] ?? 0;
        if (!is_int($displayOrder) || $displayOrder < 0) {
            $errors['display_order'] = ['Enter an integer of at least 0.'];
        }
        $isActive = $input['is_active'] ?? true;
        if (!is_bool($isActive)) {
            $errors['is_active'] = ['Enter true or false.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => is_string($description) ? $description : null,
            'display_order' => is_int($displayOrder) ? $displayOrder : 0,
            'is_active' => is_bool($isActive) ? $isActive : true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, list<string>>
     */
    private function unknownFields(array $input): array
    {
        $errors = [];
        foreach ($input as $field => $_value) {
            if (in_array($field, self::IMMUTABLE, true)) {
                $errors[$field] = ['This field is immutable.'];
            } elseif (!in_array($field, self::FIELDS, true)) {
                $errors[$field] = ['Unknown field.'];
            }
        }

        return $errors;
    }

    private function nullableString(mixed $value): string|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? trim($value) : false;
    }

    private function slugify(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = strtolower(is_string($ascii) ? $ascii : $value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized);

        return trim(is_string($slug) ? $slug : '', '-');
    }
}
