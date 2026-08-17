<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Auth;

final readonly class SameOriginPolicy
{
    private string $origin;

    public function __construct(string $applicationUrl)
    {
        $parts = parse_url($applicationUrl);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        if (!is_string($scheme) || !is_string($host)) {
            throw new \InvalidArgumentException('APP_URL must contain a scheme and host.');
        }
        $this->origin = strtolower($scheme . '://' . $host . (is_int($port) ? ':' . $port : ''));
    }

    /** @param array<string, mixed> $server */
    public function allows(array $server): bool
    {
        if (!array_key_exists('HTTP_ORIGIN', $server) && !array_key_exists('HTTP_REFERER', $server)) {
            return true;
        }

        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '') {
            return hash_equals($this->origin, strtolower(rtrim($origin, '/')));
        }
        $referer = $server['HTTP_REFERER'] ?? null;
        if (!is_string($referer) || $referer === '') {
            return false;
        }
        $parts = parse_url($referer);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        if (!is_string($scheme) || !is_string($host)) {
            return false;
        }
        $refererOrigin = strtolower($scheme . '://' . $host . (is_int($port) ? ':' . $port : ''));

        return hash_equals($this->origin, $refererOrigin);
    }
}
