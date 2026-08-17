<?php

declare(strict_types=1);

namespace ProjectSync\Exceptions;

use RuntimeException;
use Throwable;

final class PaystackException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
