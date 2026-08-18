<?php

declare(strict_types=1);

/**
 * Project Sync Notification Worker CLI
 * 
 * Standalone runner for processing background email notification jobs via cPanel cron.
 * Implements shell-level file locking (flock) to prevent overlapping cron executions.
 * 
 * Usage in cPanel Cron:
 *   * * * * * /usr/local/bin/php /home/USERNAME/public_html/scripts/process-notifications.php >> /home/USERNAME/public_html/storage/logs/cron.log 2>&1
 * 
 * Optional Argument:
 *   --limit=25 (Overrides default batch limit)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\LoggerFactory;
use ProjectSync\Infrastructure\NotificationFactory;
use ProjectSync\Services\ProcessNotificationsCommand;

$root = dirname(__DIR__);
ApplicationBootstrap::loadEnvironment($root);

$lockPath = $root . '/storage/logs/notifications.lock';
$lockHandle = @fopen($lockPath, 'c+');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo sprintf("[%s] Notification worker: another process is currently running. Skipping execution.%s", date('c'), PHP_EOL);
    exit(0);
}

try {
    $app = require $root . '/config/app.php';
    $database = require $root . '/config/database.php';

    $config = new Config([
        'app.environment' => $app['environment'] ?? 'production',
        'app.log_level' => $app['log_level'] ?? 'info',
        'db.host' => $database['host'] ?? '127.0.0.1',
        'db.port' => (string) ($database['port'] ?? 3306),
        'db.database' => $database['database'] ?? '',
        'db.username' => $database['username'] ?? '',
        'db.password' => $database['password'] ?? '',
        'mail.enabled' => $app['mail']['enabled'] ?? false,
        'mail.host' => $app['mail']['host'] ?? '',
        'mail.port' => (string) ($app['mail']['port'] ?? 587),
        'mail.username' => $app['mail']['username'] ?? '',
        'mail.password' => $app['mail']['password'] ?? '',
        'mail.encryption' => $app['mail']['encryption'] ?? 'tls',
        'mail.from_address' => $app['mail']['from_address'] ?? '',
        'mail.from_name' => $app['mail']['from_name'] ?? 'Project Sync',
        'mail.timeout_seconds' => (string) ($app['mail']['timeout_seconds'] ?? 10),
        'notifications.max_attempts' => (string) ($app['notifications']['max_attempts'] ?? 5),
        'notifications.retry_base_seconds' => (string) ($app['notifications']['retry_base_seconds'] ?? 300),
        'notifications.processing_timeout_seconds' => (string) ($app['notifications']['processing_timeout_seconds'] ?? 900),
        'notifications.batch_limit' => (string) ($app['notifications']['batch_limit'] ?? 50),
        'notifications.security_secret' => $app['notifications']['security_secret'] ?? ($app['checkout']['security_secret'] ?? 'secret'),
    ]);

    $logger = LoggerFactory::create($root . '/storage/logs/notifications.log', (string) ($app['log_level'] ?? 'info'));
    $connection = (new DatabaseConnection($config))->connect();
    $processor = NotificationFactory::processor($connection, $config, $logger);
    $batchLimit = (int) $config->requiredString('notifications.batch_limit');

    $command = new ProcessNotificationsCommand($processor, $batchLimit);
    $args = array_slice($argv ?? [], 1);
    $result = $command->run($args);

    if ($result['error'] !== '') {
        fwrite(STDERR, sprintf("[%s] Notification worker error: %s%s", date('c'), $result['error'], PHP_EOL));
        exit($result['exit_code']);
    }

    echo sprintf("[%s] %s%s", date('c'), $result['output'], PHP_EOL);
    exit($result['exit_code']);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Fatal notification worker error: %s%s", date('c'), $e->getMessage(), PHP_EOL));
    exit(1);
} finally {
    if ($lockHandle !== false) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
