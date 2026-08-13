<?php

declare(strict_types=1);

use ProjectSync\Infrastructure\ApplicationBootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

$response = ApplicationBootstrap::handle(dirname(__DIR__), $_SERVER);
ApplicationBootstrap::emit($response);
