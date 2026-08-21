<?php

declare(strict_types=1);

use ProjectSync\Infrastructure\ApplicationBootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

// Let PHP's built-in development server serve public documentation assets
// directly. All application routes still use this front controller.
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $publicRoot = realpath(__DIR__);
    $assetPath = is_string($requestPath) ? realpath(__DIR__ . '/' . ltrim($requestPath, '/')) : false;
    if (
        is_string($publicRoot)
        && is_string($assetPath)
        && str_starts_with($assetPath, $publicRoot . DIRECTORY_SEPARATOR)
        && is_file($assetPath)
        && !str_starts_with(basename($assetPath), '.')
    ) {
        return false;
    }
}

// Apache/LiteSpeed may rename Authorization after rewriting to this front controller.
if (!isset($_SERVER['HTTP_AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

$response = ApplicationBootstrap::handle(dirname(__DIR__), $_SERVER);
ApplicationBootstrap::emit($response);
