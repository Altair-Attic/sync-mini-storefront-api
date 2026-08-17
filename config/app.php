<?php

declare(strict_types=1);

$env = require __DIR__ . '/env.php';

return [
    'environment' => $env('APP_ENV', 'production'),
    'url' => $env('APP_URL'),
    'debug' => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'log_level' => $env('LOG_LEVEL', 'info'),
    'authentication' => [
        'jwt_secret' => $env('JWT_SECRET'),
        'jwt_issuer' => $env('JWT_ISSUER', $env('APP_URL')),
        'jwt_audience' => $env('JWT_AUDIENCE'),
        'jwt_access_ttl_seconds' => (int) $env('JWT_ACCESS_TTL_SECONDS', '900'),
        'jwt_clock_skew_seconds' => (int) $env('JWT_CLOCK_SKEW_SECONDS', '30'),
        'jwt_algorithm' => $env('JWT_ALGORITHM', 'HS256'),
        'refresh_token_ttl_seconds' => (int) $env('REFRESH_TOKEN_TTL_SECONDS', '2592000'),
        'refresh_cookie_name' => $env('REFRESH_COOKIE_NAME', 'project_sync_refresh'),
        'refresh_cookie_path' => $env('REFRESH_COOKIE_PATH', '/api/v1/admin'),
        'refresh_cookie_secure' => filter_var($env('REFRESH_COOKIE_SECURE', 'true'), FILTER_VALIDATE_BOOL),
        'refresh_cookie_same_site' => $env('REFRESH_COOKIE_SAME_SITE', 'Strict'),
        'refresh_token_security_secret' => $env('REFRESH_TOKEN_SECURITY_SECRET'),
        'application_origin' => $env('APP_URL'),
    ],
    'login' => [
        'max_attempts' => (int) $env('LOGIN_MAX_ATTEMPTS', '5'),
        'window_seconds' => (int) $env('LOGIN_WINDOW_SECONDS', '900'),
        'block_seconds' => (int) $env('LOGIN_BLOCK_SECONDS', '900'),
        'rate_limit_secret' => $env('RATE_LIMIT_SECRET'),
    ],
    'product_images' => [
        'max_bytes' => (int) $env('PRODUCT_IMAGE_MAX_BYTES', '5242880'),
        'max_width' => (int) $env('PRODUCT_IMAGE_MAX_WIDTH', '2000'),
        'max_height' => (int) $env('PRODUCT_IMAGE_MAX_HEIGHT', '2000'),
        'storage_path' => $env('PRODUCT_IMAGE_STORAGE_PATH', dirname(__DIR__) . '/storage/uploads/products'),
        'public_path' => $env('PRODUCT_IMAGE_PUBLIC_PATH', '/uploads/products'),
    ],
    'checkout' => [
        'security_secret' => $env('ORDER_SECURITY_SECRET', $env('RATE_LIMIT_SECRET')),
        'max_distinct_items' => (int) $env('CHECKOUT_MAX_DISTINCT_ITEMS', '50'),
        'max_quantity' => (int) $env('CHECKOUT_MAX_QUANTITY', '100'),
        'max_total_kobo' => (int) $env('CHECKOUT_MAX_TOTAL_KOBO', '4294967295'),
        'idempotency_key_max_length' => (int) $env('IDEMPOTENCY_KEY_MAX_LENGTH', '200'),
        'max_attempts' => (int) $env('CHECKOUT_MAX_ATTEMPTS', '30'),
        'confirmation_max_attempts' => (int) $env('CONFIRMATION_MAX_ATTEMPTS', '20'),
        'window_seconds' => (int) $env('CHECKOUT_WINDOW_SECONDS', '60'),
        'block_seconds' => (int) $env('CHECKOUT_BLOCK_SECONDS', '300'),
    ],
    'mail' => [
        'enabled' => filter_var($env('MAIL_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
        'host' => $env('MAIL_HOST'),
        'port' => (int) $env('MAIL_PORT', '587'),
        'username' => $env('MAIL_USERNAME'),
        'password' => $env('MAIL_PASSWORD'),
        'encryption' => strtolower($env('MAIL_ENCRYPTION', 'tls')),
        'from_address' => $env('MAIL_FROM_ADDRESS'),
        'from_name' => $env('MAIL_FROM_NAME', 'Project Sync Store'),
        'timeout_seconds' => (int) $env('MAIL_TIMEOUT_SECONDS', '10'),
    ],
    'notifications' => [
        'max_attempts' => (int) $env('NOTIFICATION_MAX_ATTEMPTS', '5'),
        'retry_base_seconds' => (int) $env('NOTIFICATION_RETRY_BASE_SECONDS', '300'),
        'processing_timeout_seconds' => (int) $env('NOTIFICATION_PROCESSING_TIMEOUT_SECONDS', '900'),
        'batch_limit' => (int) $env('NOTIFICATION_BATCH_LIMIT', '50'),
        'security_secret' => $env('NOTIFICATION_SECURITY_SECRET', $env('ORDER_SECURITY_SECRET', $env('RATE_LIMIT_SECRET'))),
    ],
];
