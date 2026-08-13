<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use ProjectSync\Controllers\HealthController;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;

final class AppFactory
{
    public static function create(string $root): Application
    {
        $app = require $root . '/config/app.php';
        $database = require $root . '/config/database.php';
        $cors = require $root . '/config/cors.php';
        $config = new Config([
            'app.environment' => $app['environment'],
            'app.debug' => $app['debug'],
            'app.log_level' => $app['log_level'],
            'cors.allowed_origins' => $cors['allowed_origins'],
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
        ]);
        $config->allowedString('app.environment', ['local', 'testing', 'staging', 'production']);
        LoggerFactory::assertValidLevel($config->requiredString('app.log_level'));
        (new DatabaseConnection($config))->validate();
        $logger = LoggerFactory::create($root . '/storage/logs/application.log', $app['log_level']);
        $routes = require $root . '/routes/api.php';

        return new Application(
            config: $config,
            logger: $logger,
            routes: $routes(new HealthController()),
            middleware: [new RequestIdMiddleware(), new CorsMiddleware($config->stringList('cors.allowed_origins'))],
        );
    }
}
