<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use ProjectSync\Controllers\HealthController;
use ProjectSync\Controllers\BusinessProfileController;
use ProjectSync\Controllers\Admin\AuthController;
use ProjectSync\Controllers\Admin\CurrentAdminController;
use ProjectSync\Infrastructure\Session\SessionManager;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Middleware\CsrfMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Services\CsrfTokenService;
use ProjectSync\Services\LoginRateLimiter;
use ProjectSync\Services\BusinessProfileService;
use ProjectSync\Validators\BusinessProfileValidator;
use ProjectSync\Validators\LoginValidator;

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
            'session.name' => $app['session']['name'], 'session.lifetime' => (string) $app['session']['lifetime'], 'session.secure_cookie' => $app['session']['secure_cookie'], 'session.same_site' => $app['session']['same_site'], 'session.domain' => $app['session']['domain'], 'session.csrf_token_ttl' => (string) $app['session']['csrf_token_ttl'],
            'login.max_attempts' => (string) $app['login']['max_attempts'], 'login.window_seconds' => (string) $app['login']['window_seconds'], 'login.block_seconds' => (string) $app['login']['block_seconds'], 'login.rate_limit_secret' => $app['login']['rate_limit_secret'],
        ]);
        $config->allowedString('app.environment', ['local', 'testing', 'staging', 'production']);
        LoggerFactory::assertValidLevel($config->requiredString('app.log_level'));
        (new DatabaseConnection($config))->validate();
        $logger = LoggerFactory::create($root . '/storage/logs/application.log', $app['log_level']);
        $routes = require $root . '/routes/api.php';
        $connection = (new DatabaseConnection($config))->connect();
        $session = new SessionManager($config);
        $csrf = new CsrfTokenService($session, (int) $config->requiredString('session.csrf_token_ttl'));
        $auth = new AuthenticationService(new MerchantUserRepository($connection), new LoginRateLimiter(new LoginAttemptRepository($connection), $config), $session, $csrf, $logger);
        $authenticationMiddleware = new AuthenticationMiddleware($auth);
        $csrfMiddleware = new CsrfMiddleware($csrf, $logger);
        $profileController = new BusinessProfileController(
            new BusinessProfileService(new BusinessProfileRepository($connection), new BusinessProfileValidator()),
            $authenticationMiddleware,
            $csrfMiddleware,
            static function (): string {
                $body = file_get_contents('php://input');

                return is_string($body) ? $body : '';
            },
        );

        return new Application(
            config: $config,
            logger: $logger,
            routes: $routes(new HealthController(), new AuthController($auth, new LoginValidator(), $csrf, $csrfMiddleware, $authenticationMiddleware), new CurrentAdminController($authenticationMiddleware), $profileController),
            middleware: [new RequestIdMiddleware(), new CorsMiddleware($config->stringList('cors.allowed_origins'))],
        );
    }
}
