<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Services\OnboardingService;
use ProjectSync\Validators\OnboardingValidator;

$file = null;
foreach ($argv as $argument) if (str_starts_with($argument, '--file=')) $file = substr($argument, 7);
$root = dirname(__DIR__);
$publicPath = realpath($root . '/public') ?: '__none__';
if (!$file || !is_file($file) || !is_readable($file) || str_starts_with(realpath($file) ?: '', $publicPath)) {
    fwrite(STDERR, "Invalid private onboarding file.\n");
    exit(1);
}

try {
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $password = getenv('ONBOARDING_ADMIN_PASSWORD');
    if (!is_string($password)) throw new RuntimeException('An onboarding password is required.');

    ApplicationBootstrap::loadEnvironment($root);
    $database = require $root . '/config/database.php';
    $connection = new DatabaseConnection(new Config(['db.host' => $database['host'], 'db.port' => (string) $database['port'], 'db.database' => $database['database'], 'db.username' => $database['username'], 'db.password' => $database['password']]));
    $db = $connection->connect();
    (new OnboardingService($db, new BusinessProfileRepository($db), new MerchantUserRepository($db), new OnboardingValidator()))->onboard($data, $password);
    fwrite(STDOUT, "Onboarding complete.\n");
} catch (Throwable) {
    fwrite(STDERR, "Onboarding failed.\n");
    exit(1);
}
