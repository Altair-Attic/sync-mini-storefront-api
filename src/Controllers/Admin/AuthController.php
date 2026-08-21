<?php

declare(strict_types=1);

namespace ProjectSync\Controllers\Admin;

use Closure;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Validators\LoginValidator;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class AuthController
{
    public function __construct(
        private AuthenticationService $auth,
        private LoginValidator $validator,
        private AuthenticationMiddleware $authentication,
        private LoggerInterface $logger,
        private Closure $readBody,
    ) {
    }

    /** @param array<string, mixed> $server */
    public function login(string $requestId, array $server): HttpResponse
    {
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

            return JsonResponse::success($result, $requestId);
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
    public function logout(string $requestId, array $server): HttpResponse
    {
        $administrator = $this->authentication->requireAdministrator($requestId, $server);
        if ($administrator instanceof HttpResponse) {
            return $administrator;
        }

        $this->logger->info('Administrator logout completed.', [
            'request_id' => $requestId,
            'administrator_id' => $administrator['id'],
        ]);

        return JsonResponse::success([], $requestId);
    }
}
