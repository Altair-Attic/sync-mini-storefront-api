<?php

declare(strict_types=1);

$env = require __DIR__ . '/env.php';

return [
    'environment' => $env('APP_ENV', 'production'),
    'url' => $env('APP_URL'),
    'debug' => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'log_level' => $env('LOG_LEVEL', 'info'),
    'session' => [
        'name' => $env('SESSION_NAME', 'project_sync_admin'),
        'lifetime' => (int) $env('SESSION_LIFETIME', '7200'),
        'secure_cookie' => filter_var($env('SESSION_SECURE_COOKIE', 'false'), FILTER_VALIDATE_BOOL),
        'same_site' => $env('SESSION_SAME_SITE', 'Lax'),
        'domain' => $env('SESSION_DOMAIN'),
        'csrf_token_ttl' => (int) $env('CSRF_TOKEN_TTL', '3600'),
    ],
    'login' => [
        'max_attempts' => (int) $env('LOGIN_MAX_ATTEMPTS', '5'),
        'window_seconds' => (int) $env('LOGIN_WINDOW_SECONDS', '900'),
        'block_seconds' => (int) $env('LOGIN_BLOCK_SECONDS', '900'),
        'rate_limit_secret' => $env('RATE_LIMIT_SECRET'),
    ],
];
