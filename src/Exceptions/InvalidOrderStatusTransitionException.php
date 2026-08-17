<?php

declare(strict_types=1);

namespace ProjectSync\Exceptions;

use RuntimeException;

final class InvalidOrderStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $previousStatus,
        public readonly string $newStatus,
        string $message = 'The requested order status transition is not permitted.',
    ) {
        parent::__construct($message);
    }
}
