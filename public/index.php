<?php

declare(strict_types=1);

use ProjectSync\Infrastructure\ApplicationBootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

// Apache/LiteSpeed may rename Authorization after rewriting to this front controller.
if (!isset($_SERVER['HTTP_AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

$response = ApplicationBootstrap::handle(dirname(__DIR__), $_SERVER);
ApplicationBootstrap::emit($response);
