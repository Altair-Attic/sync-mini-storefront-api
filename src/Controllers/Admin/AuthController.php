<?php

declare(strict_types=1);

namespace ProjectSync\Controllers\Admin;

use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Infrastructure\JsonResponse;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Middleware\CsrfMiddleware;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Services\CsrfTokenService;
use ProjectSync\Validators\LoginValidator;

final readonly class AuthController
{
    public function __construct(private AuthenticationService $auth, private LoginValidator $validator, private CsrfTokenService $csrf, private CsrfMiddleware $csrfMiddleware, private AuthenticationMiddleware $authenticationMiddleware) {}
    public function csrf(string $id): HttpResponse { return JsonResponse::success(['csrf_token' => $this->csrf->issue()], $id); }
    /** @param array<string, mixed> $server */
    public function login(string $id, array $server): HttpResponse { $failure = $this->csrfMiddleware->requireValidToken($id, $server); if ($failure !== null) return $failure; $contentType = $server['CONTENT_TYPE'] ?? null; if (!is_string($contentType) || strtolower($contentType) !== 'application/json') return JsonResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', $id, 415); $decoded = json_decode(file_get_contents('php://input') ?: '', true); if (!is_array($decoded)) return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $id, 422); $data = []; foreach ($decoded as $key => $value) if (is_string($key)) $data[$key] = $value; $ip = $server['REMOTE_ADDR'] ?? ''; if (!is_string($ip)) $ip = ''; try { $value = $this->validator->validate($data); return JsonResponse::success(['user' => $this->auth->login($value['email'], $value['password'], $ip, $id)], $id); } catch (ValidationException $exception) { return JsonResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', $id, 422, $exception->fields); } catch (\RuntimeException $exception) { return $exception->getMessage() === 'RATE_LIMITED' ? JsonResponse::error('RATE_LIMITED', 'Too many login attempts.', $id, 429) : JsonResponse::error('INVALID_CREDENTIALS', 'The email or password is incorrect.', $id, 401); } }
    /** @param array<string, mixed> $server */
    public function logout(string $id, array $server): HttpResponse { $failure = $this->csrfMiddleware->requireValidToken($id, $server); if ($failure !== null) return $failure; $administrator = $this->authenticationMiddleware->requireAdministrator($id); if ($administrator instanceof HttpResponse) return $administrator; $this->auth->logout($id); return JsonResponse::success([], $id); }
}
