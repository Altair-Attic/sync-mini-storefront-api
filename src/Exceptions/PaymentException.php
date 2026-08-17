<?php

declare(strict_types=1);

namespace ProjectSync\Exceptions;

use RuntimeException;

final class PaymentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
