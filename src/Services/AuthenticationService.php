<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Repositories\MerchantUserRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class AuthenticationService
{
    public function __construct(
        private MerchantUserRepository $users,
        private LoginRateLimiter $limiter,
        private JwtService $jwt,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array{administrator: array{id: string, name: string, email: string}, access_token: string, token_type: string, expires_in: int} */
    public function login(string $email, string $password, string $ip, string $requestId): array
    {
        if ($this->limiter->blocked($email, $ip)) {
            throw new RuntimeException('RATE_LIMITED');
        }
        $user = $this->users->findByEmail($email);
        if ($user === null || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
            $this->limiter->fail($email, $ip);
            $this->logger->warning('Login failed.', ['request_id' => $requestId]);
            throw new RuntimeException('INVALID_CREDENTIALS');
        }

        $this->limiter->clear($email, $ip);
        $this->users->touchLogin($user['id']);
        $this->logger->info('Login succeeded.', ['request_id' => $requestId, 'user_id' => $user['id']]);

        return [
            'administrator' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
            ...$this->jwt->issue($user['id']),
        ];
    }
}
