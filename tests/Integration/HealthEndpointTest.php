<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\HealthController;
use ProjectSync\Infrastructure\AppFactory;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\HttpResponse;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use Psr\Log\NullLogger;

final class HealthEndpointTest extends TestCase
{
    public function testHealthEndpointReturnsTheStandardEnvelope(): void
    {
        $routes = static function (\FastRoute\RouteCollector $router): void {
            $router->addRoute('GET', '/api/v1/health', new HealthController());
        };
        $app = new Application(new Config(['app.environment' => 'testing', 'app.debug' => false, 'cors.allowed_origins' => []]), new NullLogger(), $routes, [new RequestIdMiddleware(), new CorsMiddleware([])]);

        $response = $app->handle(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health']);

        self::assertSame(200, $response->status);
        self::assertTrue($response->body['success']);
        self::assertSame(['status' => 'ok'], $response->body['data']);
    }
}
