<?php

declare(strict_types=1);

$env = require __DIR__ . '/env.php';

return [
    'environment' => $env('APP_ENV', 'production'),
    'url' => $env('APP_URL'),
    'debug' => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'log_level' => $env('LOG_LEVEL', 'info'),
];
