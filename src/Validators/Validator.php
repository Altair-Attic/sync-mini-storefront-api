<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @param list<string> $required
     */
    public function requireFields(array $input, array $required): void
    {
        $errors = [];
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                $errors[$field] = ['This field is required.'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
