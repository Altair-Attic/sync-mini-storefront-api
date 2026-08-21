<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class ContactValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{name: string, email: string, message: string}
     */
    public function validate(array $input): array
    {
        $errors = [];
        foreach ($input as $field => $_) {
            if (!in_array($field, ['name', 'email', 'message'], true)) {
                $errors[$field] = ['Unknown or forbidden field.'];
            }
        }
        $name = $this->string($input['name'] ?? null);
        if ($name === null || mb_strlen($name) < 2 || mb_strlen($name) > 120) $errors['name'] = ['Use between 2 and 120 characters.'];
        $email = $this->string($input['email'] ?? null);
        if ($email === null || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) $errors['email'] = ['Enter a valid email address.'];
        $message = $this->string($input['message'] ?? null);
        if ($message === null || mb_strlen($message) < 10 || mb_strlen($message) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) === 1) $errors['message'] = ['Use between 10 and 2,000 characters.'];
        if ($errors !== []) throw new ValidationException($errors);

        return ['name' => $name, 'email' => strtolower($email), 'message' => $message];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
