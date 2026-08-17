<?php

declare(strict_types=1);

namespace ProjectSync\Middleware;

use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\Auth\VerifiedAccessToken;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Repositories\RevokedAccessTokenRepository;
use RuntimeException;

final readonly class AuthenticationMiddleware
{
    public function __construct(private JwtService $jwt, private MerchantUserRepository $users, private RevokedAccessTokenRepository $revokedTokens)
    {
    }

    /**
     * @param array<string, mixed> $server
     * @return array{id: string, name: string, email: string}|HttpResponse
     */
    public function requireAdministrator(string $requestId, array $server): array|HttpResponse
    {
        $header = $server['HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($header) || preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/D', $header, $matches) !== 1) {
            return $this->unauthenticated($requestId);
        }
        try {
            $token = $this->jwt->inspect($matches[1]);
        } catch (RuntimeException) {
            return $this->unauthenticated($requestId);
        }
        if ($this->revokedTokens->isRevoked($token->jwtId)) {
            return $this->unauthenticated($requestId);
        }
        $administrator = $this->users->findActive($token->administratorId);
        if ($administrator === null) {
            return $this->unauthenticated($requestId);
        }

        return $administrator;
    }

    /** @param array<string, mixed> $server */
    public function validAccessToken(array $server): ?VerifiedAccessToken
    {
        $header = $server['HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($header) || preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/D', $header, $matches) !== 1) {
            return null;
        }
        try {
            $token = $this->jwt->inspect($matches[1]);
        } catch (RuntimeException) {
            return null;
        }
        if ($this->revokedTokens->isRevoked($token->jwtId) || $this->users->findActive($token->administratorId) === null) {
            return null;
        }

        return $token;
    }

    private function unauthenticated(string $requestId): HttpResponse
    {
        return JsonResponse::error('UNAUTHENTICATED', 'Authentication is required.', $requestId, 401);
    }
}
