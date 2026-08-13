<?php

declare(strict_types=1);

$env = require __DIR__ . '/env.php';
$origins = array_filter(array_map('trim', explode(',', $env('CORS_ALLOWED_ORIGINS'))));

return ['allowed_origins' => array_values($origins)];
