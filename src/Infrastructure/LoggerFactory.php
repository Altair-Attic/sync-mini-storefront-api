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
        self::assertValidLevel($level);
        $logger = new Logger('project-sync');
        $logger->pushProcessor(new LogRedactionProcessor());
        $logger->pushHandler(new StreamHandler($path, self::level($level)));

        return $logger;
    }

    public static function assertValidLevel(string $level): void
    {
        if (!in_array(strtolower($level), ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            throw new \ProjectSync\Exceptions\ConfigurationException('Configuration value "app.log_level" is invalid.');
        }
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
            default => throw new \LogicException('A validated log level was not mapped.'),
        };
    }
}
