<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use InvalidArgumentException;

final readonly class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Configuration value "%s" must be a string.', $key));
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->values[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Configuration value "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Configuration value "%s" must be a string list.', $key));
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException(sprintf('Configuration value "%s" must be a string list.', $key));
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
