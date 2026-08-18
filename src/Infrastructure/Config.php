<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use InvalidArgumentException;
use ProjectSync\Exceptions\ConfigurationException;

final readonly class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Configuration value "%s" must be a string.', $key));
        }

        return $value;
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->values[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Configuration value "%s" must be a boolean.', $key));
        }

        return $value;
    }

    public function requiredString(string $key): string
    {
        $value = trim($this->string($key));
        if ($value === '') {
            throw new ConfigurationException(sprintf('Configuration value "%s" is required.', $key));
        }

        return $value;
    }

    /** @param list<string> $allowedValues */
    public function allowedString(string $key, array $allowedValues): string
    {
        $value = $this->requiredString($key);
        if (!in_array($value, $allowedValues, true)) {
            throw new ConfigurationException(sprintf('Configuration value "%s" is invalid.', $key));
        }

        return $value;
    }

    public function port(string $key): int
    {
        $value = $this->requiredString($key);
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            throw new ConfigurationException(sprintf('Configuration value "%s" must be a valid TCP port.', $key));
        }

        return (int) $value;
    }

    /**
     * @param list<string>|null $default
     * @return list<string>
     */
    public function stringList(string $key, ?array $default = null): array
    {
        $value = $this->values[$key] ?? $default;
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
