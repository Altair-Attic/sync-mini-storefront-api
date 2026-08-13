<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class LoginValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{email: string, password: string}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;
        if (!is_string($email) || trim($email) === '') $errors['email'] = ['Enter an email address.'];
        elseif (strlen($email) > 254 || filter_var(trim(strtolower($email)), FILTER_VALIDATE_EMAIL) === false) $errors['email'] = ['Enter a valid email address.'];
        if (!is_string($password) || $password === '') $errors['password'] = ['Enter a password.'];
        elseif (strlen($password) > 1024) $errors['password'] = ['Enter a valid password.'];
        if ($errors !== []) throw new ValidationException($errors);
        if (!is_string($email) || !is_string($password)) throw new \LogicException('Validated login input was not normalized.');
        return ['email' => strtolower(trim($email)), 'password' => $password];
    }
}
