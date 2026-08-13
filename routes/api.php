<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use ProjectSync\Controllers\BusinessProfileController;
use ProjectSync\Controllers\HealthController;
use ProjectSync\Controllers\Admin\AuthController;
use ProjectSync\Controllers\Admin\CurrentAdminController;

return static function (HealthController $health, AuthController $auth, CurrentAdminController $current, BusinessProfileController $profile): callable {
    return static function (RouteCollector $router) use ($health, $auth, $current, $profile): void {
        $router->addRoute('GET', '/api/v1/health', $health);
        $router->addRoute('GET', '/api/v1/store', [$profile, 'store']);
        $router->addRoute('GET', '/api/v1/admin/csrf-token', [$auth, 'csrf']);
        $router->addRoute('POST', '/api/v1/admin/login', [$auth, 'login']);
        $router->addRoute('GET', '/api/v1/admin/me', [$current, 'me']);
        $router->addRoute('GET', '/api/v1/admin/profile', [$profile, 'admin']);
        $router->addRoute('PUT', '/api/v1/admin/profile', [$profile, 'update']);
        $router->addRoute('POST', '/api/v1/admin/logout', [$auth, 'logout']);
    };
};
