<?php

declare(strict_types=1);

namespace ProjectSync\Middleware;

use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Repositories\MerchantUserRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class AuthenticationMiddleware
{
    public function __construct(
        private JwtService $jwt,
        private MerchantUserRepository $users,
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * @param array<string, mixed> $server
     * @return array{id: string, name: string, email: string}|HttpResponse
     */
    public function requireAdministrator(string $requestId, array $server): array|HttpResponse
    {
        $header = $this->authorizationHeader($server);
        if (!is_string($header) || preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/D', $header, $matches) !== 1) {
            return $this->unauthenticated($requestId, 'missing_or_malformed_header');
        }
        try {
            $administratorId = $this->jwt->verify($matches[1]);
        } catch (RuntimeException) {
            return $this->unauthenticated($requestId, 'invalid_jwt');
        }
        $administrator = $this->users->findActive($administratorId);
        if ($administrator === null) {
            return $this->unauthenticated($requestId, 'inactive_or_missing_admin');
        }

        return $administrator;
    }

    /**
     * Apache may expose Authorization as REDIRECT_HTTP_AUTHORIZATION after
     * mod_rewrite forwards the request to the PHP front controller.
     *
     * @param array<string, mixed> $server
     */
    private function authorizationHeader(array $server): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value = $server[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function unauthenticated(string $requestId, string $reason = 'invalid_credentials'): HttpResponse
    {
        $this->logger?->warning('Administrator authentication rejected.', [
            'request_id' => $requestId,
            'reason' => $reason,
        ]);

        return JsonResponse::error('UNAUTHENTICATED', 'Authentication is required.', $requestId, 401);
    }
}
