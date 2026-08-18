<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use Dotenv\Dotenv;
use ProjectSync\Middleware\RequestIdMiddleware;
use Throwable;

final class ApplicationBootstrap
{
    /** @param array<string, mixed> $server */
    public static function handle(string $root, array $server): HttpResponse
    {
        try {
            self::loadEnvironment($root);

            return AppFactory::create($root)->handle($server);
        } catch (Throwable $exception) {
            $requestId = (new RequestIdMiddleware())->requestId();
            self::logFailure($root, $requestId, $exception);

            return JsonResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', $requestId, 500);
        }
    }

    public static function loadEnvironment(string $root): void
    {
        if (getenv('APP_ENV') === 'testing' && is_file($root . '/.env.testing')) {
            self::copyProcessEnvironmentValue('RUN_DB_INTEGRATION_TESTS');
            Dotenv::createImmutable($root, '.env.testing')->safeLoad();

            return;
        }
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }
    }

    private static function copyProcessEnvironmentValue(string $key): void
    {
        $value = getenv($key);
        if (is_string($value)) $_ENV[$key] = $value;
    }

    public static function emit(HttpResponse $response): void
    {
        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($response->status === 204) {
            return;
        }

        if ($response->rawBody !== null) {
            echo $response->rawBody;
            return;
        }

        try {
            echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"success":false,"error":{"code":"INTERNAL_ERROR","message":"An unexpected error occurred."},"meta":{"request_id":"req_response_encoding_failed"}}';
        }
    }

    private static function logFailure(string $root, string $requestId, Throwable $exception): void
    {
        try {
            LoggerFactory::create($root . '/storage/logs/application.log', 'error')->error('Application bootstrap failed.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);
        } catch (Throwable) {
            error_log(sprintf('Project Sync bootstrap failure. Request ID: %s', $requestId));
        }
    }
}
