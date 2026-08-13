<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Infrastructure\Session\SessionManager;
use ProjectSync\Repositories\MerchantUserRepository;
use Psr\Log\LoggerInterface;

final readonly class AuthenticationService
{
    public function __construct(private MerchantUserRepository $users, private LoginRateLimiter $limiter, private SessionManager $session, private CsrfTokenService $csrf, private LoggerInterface $logger) {}
    /** @return array{id: string, name: string, email: string} */
    public function login(string $email, string $password, string $ip, string $requestId): array { if ($this->limiter->blocked($email, $ip)) throw new \RuntimeException('RATE_LIMITED'); $user = $this->users->findByEmail($email); if ($user === null || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) { $this->limiter->fail($email, $ip); $this->logger->warning('Login failed.', ['request_id' => $requestId]); throw new \RuntimeException('INVALID_CREDENTIALS'); } $this->limiter->clear($email, $ip); $this->session->regenerate(); $_SESSION['admin_id'] = $user['id']; $this->users->touchLogin($user['id']); $this->csrf->rotate(); $this->logger->info('Login succeeded.', ['request_id' => $requestId, 'user_id' => $user['id']]); return ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']]; }
    /** @return array{id: string, name: string, email: string}|null */
    public function current(): ?array { $this->session->start(); $administratorId = $_SESSION['admin_id'] ?? null; return is_string($administratorId) ? $this->users->findActive($administratorId) : null; }
    public function logout(string $requestId): void { $this->session->destroy(); $this->logger->info('Logout completed.', ['request_id' => $requestId]); }
}
