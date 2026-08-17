<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\Auth\VerifiedAccessToken;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\AdminRefreshTokenRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Repositories\RevokedAccessTokenRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final readonly class AuthenticationService
{
    public function __construct(
        private MerchantUserRepository $users,
        private AdminRefreshTokenRepository $refreshTokens,
        private RevokedAccessTokenRepository $revokedAccessTokens,
        private LoginRateLimiter $limiter,
        private JwtService $jwt,
        private string $refreshHashSecret,
        private int $refreshTtlSeconds,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array{administrator: array{id: string, name: string, email: string}, access_token: string, token_type: string, expires_in: int, refresh_token: string} */
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
        $refresh = $this->createRefreshToken($user['id'], UuidGenerator::v4());
        $this->users->touchLogin($user['id']);
        $this->refreshTokens->cleanupExpired();
        $this->logger->info('Login succeeded.', ['request_id' => $requestId, 'user_id' => $user['id']]);

        return [
            'administrator' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
            ...$this->jwt->issue($user['id']),
            'refresh_token' => $refresh,
        ];
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string} */
    public function refresh(string $rawToken, string $requestId): array
    {
        $this->refreshTokens->begin();
        try {
            $record = $this->refreshTokens->lockByHash($this->hash($rawToken));
            if ($record === null) {
                throw new RuntimeException('UNAUTHENTICATED');
            }
            if ($record['replaced_by_token_id'] !== null) {
                $this->refreshTokens->revokeFamily($record['family_id']);
                $this->refreshTokens->commit();
                $this->logger->warning('Rotated refresh token reused; family revoked.', ['request_id' => $requestId, 'user_id' => $record['merchant_user_id']]);
                throw new RuntimeException('UNAUTHENTICATED');
            }
            if ($record['revoked_at'] !== null || strtotime($record['expires_at'] . ' UTC') <= time()) {
                throw new RuntimeException('UNAUTHENTICATED');
            }
            $administrator = $this->users->findActive($record['merchant_user_id']);
            if ($administrator === null) {
                $this->refreshTokens->revokeFamily($record['family_id']);
                $this->refreshTokens->commit();
                throw new RuntimeException('UNAUTHENTICATED');
            }
            $replacementId = UuidGenerator::v4();
            $replacement = $this->randomToken();
            $this->refreshTokens->create($replacementId, $record['merchant_user_id'], $this->hash($replacement), $record['family_id'], $this->expiry());
            $this->refreshTokens->markRotated($record['id'], $replacementId);
            $this->refreshTokens->commit();
            $this->refreshTokens->cleanupExpired();
            $this->logger->info('Refresh token rotated.', ['request_id' => $requestId, 'user_id' => $record['merchant_user_id']]);

            return [...$this->jwt->issue($record['merchant_user_id']), 'refresh_token' => $replacement];
        } catch (Throwable $exception) {
            $this->refreshTokens->rollback();
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'UNAUTHENTICATED') {
                throw $exception;
            }
            throw $exception;
        }
    }

    public function logout(?string $rawToken, ?VerifiedAccessToken $accessToken, string $requestId): void
    {
        if ($rawToken !== null || $accessToken !== null) {
            $this->refreshTokens->begin();
            try {
                if ($rawToken !== null) {
                    $record = $this->refreshTokens->lockByHash($this->hash($rawToken));
                    if ($record !== null) {
                        $this->refreshTokens->revokeFamily($record['family_id']);
                    }
                }
                if ($accessToken !== null) {
                    $this->revokedAccessTokens->revoke($accessToken->jwtId, $accessToken->administratorId, $accessToken->expiresAt);
                }
                $this->refreshTokens->commit();
            } catch (Throwable $exception) {
                $this->refreshTokens->rollback();
                throw $exception;
            }
        }
        $this->revokedAccessTokens->cleanupExpired();
        $this->logger->info('Logout completed.', ['request_id' => $requestId]);
    }

    private function createRefreshToken(string $administratorId, string $familyId): string
    {
        $token = $this->randomToken();
        $this->refreshTokens->create(UuidGenerator::v4(), $administratorId, $this->hash($token), $familyId, $this->expiry());

        return $token;
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->refreshHashSecret);
    }

    private function expiry(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $this->refreshTtlSeconds . ' seconds')->format('Y-m-d H:i:s');
    }
}
