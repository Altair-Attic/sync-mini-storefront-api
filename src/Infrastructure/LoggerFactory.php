<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(string $path, string $level): LoggerInterface
    {
        $logger = new Logger('project-sync');
        $logger->pushHandler(new StreamHandler($path, self::level($level)));

        return $logger;
    }

    private static function level(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }
}
