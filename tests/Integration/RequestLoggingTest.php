<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProjectSync\Controllers\HealthController;
use ProjectSync\Infrastructure\Application;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use Psr\Log\AbstractLogger;

final class RequestLoggingTest extends TestCase
{
    public function testItLogsEveryCompletedRequestWithOperationalContext(): void
    {
        $logger = new InMemoryLogger();
        $routes = static function (\FastRoute\RouteCollector $router): void {
            $router->addRoute('GET', '/api/v1/health', new HealthController());
        };
        $app = new Application(new Config(['app.environment' => 'testing', 'app.debug' => false, 'cors.allowed_origins' => []]), $logger, $routes, [new RequestIdMiddleware(), new CorsMiddleware([])]);

        $response = $app->handle(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health']);

        self::assertSame(200, $response->status);
        self::assertCount(1, $logger->records);
        self::assertSame('API request completed.', $logger->records[0]['message']);
        self::assertSame('GET', $logger->records[0]['context']['method']);
        self::assertSame('/api/v1/health', $logger->records[0]['context']['route']);
        self::assertSame(200, $logger->records[0]['context']['status_code']);
        self::assertSame('testing', $logger->records[0]['context']['environment']);
    }
}

final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
