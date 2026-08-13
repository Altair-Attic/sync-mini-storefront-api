<?php

declare(strict_types=1);

namespace ProjectSync\Exceptions;

use RuntimeException;
use Throwable;

final class MigrationException extends RuntimeException
{
    public static function failed(string $migration, Throwable $previous): self
    {
        return new self(sprintf('Migration "%s" failed.', $migration), 0, $previous);
    }
}
