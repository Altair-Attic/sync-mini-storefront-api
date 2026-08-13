<?php

declare(strict_types=1);

/**
 * @return Closure(string, string): string
 */
return static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};
