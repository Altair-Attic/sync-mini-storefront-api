<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use Closure;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use Psr\Log\LoggerInterface;
use Throwable;
use function FastRoute\simpleDispatcher;

final readonly class Application
{
    /**
     * @param Closure(RouteCollector): void $routes
     * @param list<RequestIdMiddleware|CorsMiddleware> $middleware
     */
    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
        private Closure $routes,
        private array $middleware,
    ) {
    }

    /** @param array<string, mixed> $server */
    public function handle(array $server): HttpResponse
    {
        $requestId = '';
        $method = 'GET';
        $uri = '/';
        $origin = null;
        $startedAt = hrtime(true);
        $response = null;
        try {
            $methodValue = $server['REQUEST_METHOD'] ?? 'GET';
            $uriValue = $server['REQUEST_URI'] ?? '/';
            $originValue = $server['HTTP_ORIGIN'] ?? null;
            $method = strtoupper(is_string($methodValue) ? $methodValue : 'GET');
            $path = parse_url(is_string($uriValue) ? $uriValue : '/', PHP_URL_PATH);
            $uri = is_string($path) ? $path : '/';
            $origin = is_string($originValue) ? $originValue : null;
            foreach ($this->middleware as $middleware) {
                if ($middleware instanceof RequestIdMiddleware) {
                    $requestId = $middleware->requestId();
                }
                if ($middleware instanceof CorsMiddleware) {
                    $preflight = $middleware->preflight($method, $origin, $requestId);
                    if ($preflight !== null) {
                        $response = $preflight;

                        return $response;
                    }
                }
            }

            $dispatcher = simpleDispatcher($this->routes);
            $routeInfo = $dispatcher->dispatch($method, $uri);
            if ($routeInfo[0] === Dispatcher::NOT_FOUND) {
                $response = JsonResponse::error('NOT_FOUND', 'The requested resource was not found.', $requestId, 404);
            } elseif ($routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED) {
                $response = JsonResponse::error('METHOD_NOT_ALLOWED', 'The HTTP method is not allowed for this resource.', $requestId, 405);
            } else {
                /** @var callable $handler */
                $handler = $routeInfo[1];
                $response = $handler($requestId, $server, $routeInfo[2]);
            }

            $response = $this->withSecurityHeaders($response, $server);
            $response = $this->withCors($response, $origin);

            return $response;
        } catch (Throwable $exception) {
            $requestId = $requestId !== '' ? $requestId : bin2hex(random_bytes(12));
            $this->logger->error('Unhandled API exception.', ['request_id' => $requestId, 'exception' => $exception]);
            $response = JsonResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', $requestId, 500);
            $response = $this->withSecurityHeaders($response, $server);
            $response = $this->withCors($response, $origin);

            return $response;
        } finally {
            if ($response instanceof HttpResponse) {
                $this->logCompletion($requestId, $method, $uri, $response, $startedAt);
            }
        }
    }

    /** @param array<string, mixed> $server */
    private function withSecurityHeaders(HttpResponse $response, array $server): HttpResponse
    {
        $headers = $response->headers;

        if (!isset($headers['X-Content-Type-Options'])) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }
        if (!isset($headers['X-Frame-Options'])) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }
        if (!isset($headers['Referrer-Policy'])) {
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        }

        $proto = is_string($server['HTTP_X_FORWARDED_PROTO'] ?? null) ? strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) : '';
        $port = is_scalar($server['SERVER_PORT'] ?? null) ? (int) $server['SERVER_PORT'] : 0;
        $isHttps = (isset($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || ($proto === 'https')
            || ($port === 443);

        if ($this->config->bool('app.hsts_enabled', false) && ($isHttps || $this->config->string('app.environment', 'local') === 'production')) {
            $maxAge = (int) $this->config->string('app.hsts_max_age', '31536000');
            $headers['Strict-Transport-Security'] = sprintf('max-age=%d; includeSubDomains', $maxAge);
        }

        return new HttpResponse($response->status, $headers, $response->body, $response->rawBody);
    }

    private function withCors(HttpResponse $response, ?string $origin): HttpResponse
    {
        if ($origin === null || !in_array($origin, $this->config->stringList('cors.allowed_origins', []), true)) {
            return $response;
        }

        $headers = $response->headers;
        $headers['Access-Control-Allow-Origin'] = $origin;
        $headers['Vary'] = 'Origin';
        $headers['Access-Control-Allow-Credentials'] = 'true';
        return new HttpResponse($response->status, $headers, $response->body, $response->rawBody);
    }

    private function logCompletion(string $requestId, string $method, string $uri, HttpResponse $response, int $startedAt): void
    {
        $error = $response->body['error'] ?? null;
        $errorCode = is_array($error) ? ($error['code'] ?? null) : null;
        try {
            $this->logger->info('API request completed.', [
                'request_id' => $requestId,
                'environment' => $this->config->string('app.environment', 'local'),
                'route' => $uri,
                'method' => $method,
                'status_code' => $response->status,
                'error_code' => is_string($errorCode) ? $errorCode : null,
                'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
            ]);
        } catch (Throwable) {
            // Logging failures must not change an API response.
        }
    }
}
