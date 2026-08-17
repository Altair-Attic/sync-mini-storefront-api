<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class OrderStatusUpdateValidator
{
    /** @var list<string> */
    public const array ALLOWED_STATUSES = [
        'new',
        'confirmed',
        'processing',
        'ready',
        'completed',
        'cancelled',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{status: string}
     */
    public function validate(array $input): array
    {
        $errors = [];
        foreach ($input as $field => $_value) {
            if ($field !== 'status') {
                $errors[$field] = ['Unknown field.'];
            }
        }

        if (!array_key_exists('status', $input)) {
            $errors['status'] = ['The status field is required.'];
        } else {
            $status = $input['status'];
            if (!is_string($status) || trim($status) === '') {
                $errors['status'] = ['Status must be a non-empty string.'];
            } elseif (!in_array(strtolower(trim($status)), self::ALLOWED_STATUSES, true)) {
                $errors['status'] = ['Choose a valid order status.'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var string $status */
        $status = $input['status'];

        return [
            'status' => strtolower(trim($status)),
        ];
    }
}
