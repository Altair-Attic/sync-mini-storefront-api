<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\LoggerFactory;
use ProjectSync\Infrastructure\NotificationFactory;
use ProjectSync\Services\ProcessNotificationsCommand;

$arguments = array_slice($argv, 1);

$root = dirname(__DIR__);
try {
    ApplicationBootstrap::loadEnvironment($root);
    $app = require $root . '/config/app.php';
    $database = require $root . '/config/database.php';
    $config = new Config([
        'db.host' => $database['host'], 'db.port' => (string) $database['port'], 'db.database' => $database['database'],
        'db.username' => $database['username'], 'db.password' => $database['password'],
        'mail.enabled' => $app['mail']['enabled'], 'mail.host' => $app['mail']['host'], 'mail.port' => (string) $app['mail']['port'],
        'mail.username' => $app['mail']['username'], 'mail.password' => $app['mail']['password'], 'mail.encryption' => $app['mail']['encryption'],
        'mail.from_address' => $app['mail']['from_address'], 'mail.from_name' => $app['mail']['from_name'], 'mail.timeout_seconds' => (string) $app['mail']['timeout_seconds'],
        'notifications.retry_base_seconds' => (string) $app['notifications']['retry_base_seconds'],
        'notifications.processing_timeout_seconds' => (string) $app['notifications']['processing_timeout_seconds'],
    ]);
    $maximumBatchLimit = (int) $app['notifications']['batch_limit'];
    foreach ($arguments as $argument) {
        $value = str_starts_with($argument, '--limit=') ? substr($argument, 8) : '';
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $maximumBatchLimit) {
            fwrite(STDERR, "Invalid --limit.\n");
            exit(2);
        }
    }
    $db = (new DatabaseConnection($config))->connect();
    $logger = LoggerFactory::create($root . '/storage/logs/application.log', $app['log_level']);
    if (!$config->bool('mail.enabled')) {
        fwrite(STDOUT, "Mail delivery disabled; no jobs processed.\n");
        exit(0);
    }
    $result = (new ProcessNotificationsCommand(NotificationFactory::processor($db, $config, $logger), $maximumBatchLimit))->run($arguments);
    if ($result['output'] !== '') {
        fwrite(STDOUT, $result['output'] . "\n");
    }
    if ($result['error'] !== '') {
        fwrite(STDERR, $result['error'] . "\n");
    }
    exit($result['exit_code']);
} catch (Throwable) {
    fwrite(STDERR, "Notification processing failed.\n");
    exit(1);
}
