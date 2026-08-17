<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Auth;

final readonly class RefreshCookie
{
    public function __construct(
        private string $name,
        private string $path,
        private bool $secure,
        private string $sameSite,
        private int $ttlSeconds,
    ) {
    }

    /** @param array<string, mixed> $server */
    public function read(array $server): ?string
    {
        $header = $server['HTTP_COOKIE'] ?? null;
        if (!is_string($header)) {
            return null;
        }
        $matches = [];
        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && $pair[0] === $this->name) {
                $matches[] = rawurldecode($pair[1]);
            }
        }

        return count($matches) === 1 && $matches[0] !== '' ? $matches[0] : null;
    }

    public function setHeader(string $token): string
    {
        return $this->header(rawurlencode($token), $this->ttlSeconds, time() + $this->ttlSeconds);
    }

    public function expireHeader(): string
    {
        return $this->header('', 0, 1);
    }

    private function header(string $value, int $maxAge, int $expires): string
    {
        $secure = $this->secure ? '; Secure' : '';

        return sprintf('%s=%s; Path=%s; Max-Age=%d; Expires=%s%s; HttpOnly; SameSite=%s', $this->name, $value, $this->path, $maxAge, gmdate('D, d M Y H:i:s', $expires) . ' GMT', $secure, $this->sameSite);
    }
}
