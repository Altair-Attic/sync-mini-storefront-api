<?php

declare(strict_types=1);

namespace ProjectSync\Controllers\Admin;

use Closure;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\Auth\RefreshCookie;
use ProjectSync\Infrastructure\Auth\SameOriginPolicy;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Validators\LoginValidator;
use RuntimeException;

final readonly class AuthController
{
    public function __construct(
        private AuthenticationService $auth,
        private LoginValidator $validator,
        private RefreshCookie $cookie,
        private SameOriginPolicy $origin,
        private AuthenticationMiddleware $authentication,
        private Closure $readBody,
    ) {
    }

    /** @param array<string, mixed> $server */
    public function login(string $requestId, array $server): HttpResponse
    {
        if (!$this->origin->allows($server)) {
            return $this->originFailure($requestId);
        }
        $contentType = $server['CONTENT_TYPE'] ?? null;
        if (!is_string($contentType) || strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
            return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', $requestId, 415);
        }
        $decoded = json_decode(($this->readBody)(), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422);
        }
        $data = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }
        $ip = $server['REMOTE_ADDR'] ?? '';
        try {
            $credentials = $this->validator->validate($data);
            $result = $this->auth->login($credentials['email'], $credentials['password'], is_string($ip) ? $ip : '', $requestId);
            $refreshToken = $result['refresh_token'];
            unset($result['refresh_token']);

            return $this->withCookie(JsonResponse::success($result, $requestId), $this->cookie->setHeader($refreshToken));
        } catch (ValidationException $exception) {
            return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $requestId, 422, $exception->fields);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'RATE_LIMITED') {
                return JsonResponse::error('RATE_LIMITED', 'Too many login attempts.', $requestId, 429);
            }
            if ($exception->getMessage() === 'INVALID_CREDENTIALS') {
                return JsonResponse::error('INVALID_CREDENTIALS', 'The email or password is incorrect.', $requestId, 401);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $server */
    public function refresh(string $requestId, array $server): HttpResponse
    {
        if (!$this->origin->allows($server)) {
            return $this->originFailure($requestId);
        }
        $token = $this->cookie->read($server);
        if ($token === null) {
            return $this->unauthenticated($requestId);
        }
        try {
            $result = $this->auth->refresh($token, $requestId);
            $replacement = $result['refresh_token'];
            unset($result['refresh_token']);

            return $this->withCookie(JsonResponse::success($result, $requestId), $this->cookie->setHeader($replacement));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'UNAUTHENTICATED') {
                throw $exception;
            }
            return $this->withCookie($this->unauthenticated($requestId), $this->cookie->expireHeader());
        }
    }

    /** @param array<string, mixed> $server */
    public function logout(string $requestId, array $server): HttpResponse
    {
        if (!$this->origin->allows($server)) {
            return $this->originFailure($requestId);
        }
        $this->auth->logout($this->cookie->read($server), $this->authentication->validAccessToken($server), $requestId);

        return $this->withCookie(JsonResponse::success([], $requestId), $this->cookie->expireHeader());
    }

    private function withCookie(HttpResponse $response, string $cookie): HttpResponse
    {
        return new HttpResponse($response->status, $response->headers + ['Set-Cookie' => $cookie], $response->body);
    }

    private function unauthenticated(string $requestId): HttpResponse
    {
        return JsonResponse::error('UNAUTHENTICATED', 'Authentication is required.', $requestId, 401);
    }

    private function originFailure(string $requestId): HttpResponse
    {
        return JsonResponse::error('ORIGIN_NOT_ALLOWED', 'The request origin is not allowed.', $requestId, 403);
    }
}
