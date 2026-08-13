<?php

declare(strict_types=1);

namespace ProjectSync\Exceptions;

use RuntimeException;

final class UploadException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
