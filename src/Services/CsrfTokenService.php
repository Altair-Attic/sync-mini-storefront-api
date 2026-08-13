<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Infrastructure\Session\SessionManager;
final readonly class CsrfTokenService
{
    public function __construct(private SessionManager $session, private int $ttl) {}
    public function issue(): string { $this->session->start(); $token = $_SESSION['csrf_token'] ?? null; $issuedAt = $_SESSION['csrf_issued_at'] ?? null; if (!is_string($token) || !is_int($issuedAt) || time() - $issuedAt > $this->ttl) { $this->rotate(); $token = $_SESSION['csrf_token'] ?? null; } if (!is_string($token)) throw new \RuntimeException('CSRF token could not be issued.'); return $token; }
    public function rotate(): void { $this->session->start(); $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $_SESSION['csrf_issued_at'] = time(); }
    public function valid(?string $token): bool { $this->session->start(); return is_string($token) && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token); }
}
