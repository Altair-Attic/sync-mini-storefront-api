<?php

declare(strict_types=1);

namespace ProjectSync\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Request validation failed.');
    }
}
