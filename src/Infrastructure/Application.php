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
                        return $preflight;
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
                /** @var callable(string): HttpResponse $handler */
                $handler = $routeInfo[1];
                $response = $handler($requestId);
            }

            return $this->withCors($response, $origin);
        } catch (Throwable $exception) {
            $requestId = $requestId !== '' ? $requestId : bin2hex(random_bytes(12));
            $this->logger->error('Unhandled API exception.', ['request_id' => $requestId, 'exception' => $exception]);
            return JsonResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', $requestId, 500);
        }
    }

    private function withCors(HttpResponse $response, ?string $origin): HttpResponse
    {
        if ($origin === null || !in_array($origin, $this->config->stringList('cors.allowed_origins'), true)) {
            return $response;
        }

        $headers = $response->headers;
        $headers['Access-Control-Allow-Origin'] = $origin;
        $headers['Vary'] = 'Origin';
        return new HttpResponse($response->status, $headers, $response->body);
    }
}
