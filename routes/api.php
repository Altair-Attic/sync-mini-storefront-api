<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use ProjectSync\Controllers\HealthController;

return static function (HealthController $health): callable {
    return static function (RouteCollector $router) use ($health): void {
        $router->addRoute('GET', '/api/v1/health', $health);
    };
};
