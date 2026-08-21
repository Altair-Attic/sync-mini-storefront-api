<?php

declare(strict_types=1);

use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Services\MerchantBootstrapService;
use ProjectSync\Validators\BusinessProfileValidator;
use ProjectSync\Validators\MerchantBootstrapValidator;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{profile_exists: bool, administrator_exists: bool} */
function bootstrapStatus(MerchantBootstrapService $service): array
{
    return $service->status();
}

function bootstrapPrompt(string $label, ?string $default = null, bool $nullable = false): ?string
{
    $suffix = $default === null ? '' : sprintf(' [%s]', $default);
    fwrite(STDOUT, $label . $suffix . ': ');
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Interactive input was interrupted.');
    }
    $value = trim($value);
    if ($value === '') {
        return $nullable ? null : $default;
    }

    return $value;
}

function bootstrapBoolean(string $label, bool $default): bool
{
    $answer = bootstrapPrompt($label . ' (yes/no)', $default ? 'yes' : 'no');
    if ($answer === null) {
        throw new RuntimeException('A yes or no answer is required.');
    }
    $normalized = strtolower($answer);
    if (in_array($normalized, ['yes', 'y'], true)) {
        return true;
    }
    if (in_array($normalized, ['no', 'n'], true)) {
        return false;
    }

    throw new RuntimeException('Enter yes or no.');
}

function bootstrapInteger(string $label, int $default): int
{
    $answer = bootstrapPrompt($label, (string) $default);
    if (!is_string($answer) || !ctype_digit($answer)) {
        throw new RuntimeException('Enter a non-negative whole number.');
    }

    return (int) $answer;
}

function bootstrapPassword(string $label): string
{
    if (!function_exists('shell_exec')) {
        throw new RuntimeException('This server cannot securely read a password interactively.');
    }
    $state = shell_exec('stty -g');
    if (!is_string($state) || trim($state) === '') {
        throw new RuntimeException('Run this command from an interactive terminal so the password can be hidden.');
    }

    fwrite(STDOUT, $label . ': ');
    try {
        shell_exec('stty -echo');
        $password = fgets(STDIN);
    } finally {
        shell_exec('stty ' . escapeshellarg(trim($state)));
        fwrite(STDOUT, PHP_EOL);
    }
    if ($password === false) {
        throw new RuntimeException('Interactive input was interrupted.');
    }

    return rtrim($password, "\r\n");
}

try {
    $root = dirname(__DIR__);
    ApplicationBootstrap::loadEnvironment($root);
    $database = require $root . '/config/database.php';
    $db = (new DatabaseConnection(new Config([
        'db.host' => $database['host'],
        'db.port' => (string) $database['port'],
        'db.database' => $database['database'],
        'db.username' => $database['username'],
        'db.password' => $database['password'],
    ])))->connect();
    $service = new MerchantBootstrapService(
        $db,
        new BusinessProfileRepository($db),
        new MerchantUserRepository($db),
        new MerchantBootstrapValidator(new BusinessProfileValidator()),
    );
    $status = bootstrapStatus($service);
    if ($status['profile_exists'] && $status['administrator_exists']) {
        fwrite(STDOUT, "Merchant setup is already complete. No changes were made.\n");
        exit(0);
    }

    $profile = null;
    if (!$status['profile_exists']) {
        fwrite(STDOUT, "Enter the business profile details.\n");
        $profile = [
            'business_name' => bootstrapPrompt('Business name'),
            'slug' => bootstrapPrompt('Store slug'),
            'domain' => bootstrapPrompt('Store domain'),
            'whatsapp_number' => bootstrapPrompt('WhatsApp number'),
            'support_email' => bootstrapPrompt('Support email (leave blank for none)', nullable: true),
            'order_notification_email' => bootstrapPrompt('Order notification email (leave blank to use support email)', nullable: true),
            'logo_url' => bootstrapPrompt('Logo HTTPS URL (leave blank for none)', nullable: true),
            'template_id' => bootstrapPrompt('Template ID', 'default'),
            'currency' => 'NGN',
            'timezone' => bootstrapPrompt('Timezone', 'Africa/Lagos'),
            'delivery_enabled' => bootstrapBoolean('Enable delivery', true),
            'pickup_enabled' => bootstrapBoolean('Enable pickup', true),
            'fixed_delivery_fee_kobo' => bootstrapInteger('Fixed delivery fee in kobo', 0),
            'merchant_email_notifications_enabled' => bootstrapBoolean('Send merchant order emails', true),
            'customer_email_notifications_enabled' => bootstrapBoolean('Send customer order emails', false),
            'whatsapp_handoff_enabled' => bootstrapBoolean('Enable WhatsApp handoff', true),
        ];
    }

    $administrator = null;
    $password = null;
    if (!$status['administrator_exists']) {
        fwrite(STDOUT, "Enter the first administrator details.\n");
        $administrator = [
            'name' => bootstrapPrompt('Administrator name'),
            'email' => bootstrapPrompt('Administrator email'),
        ];
        $password = bootstrapPassword('Administrator password (minimum 12 characters)');
        $confirmation = bootstrapPassword('Confirm administrator password');
        if (!hash_equals($password, $confirmation)) {
            throw new RuntimeException('The administrator passwords do not match.');
        }
    }

    $result = $service->bootstrap($profile, $administrator, $password);
    fwrite(STDOUT, sprintf(
        "Merchant bootstrap complete. Profile created: %s. Administrator created: %s.\n",
        $result->profileCreated ? 'yes' : 'no',
        $result->administratorCreated ? 'yes' : 'no',
    ));
} catch (ValidationException $exception) {
    fwrite(STDERR, "Merchant bootstrap failed validation:\n");
    foreach ($exception->fields as $field => $messages) {
        fwrite(STDERR, sprintf('- %s: %s%s', $field, implode(' ', $messages), PHP_EOL));
    }
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Merchant bootstrap failed. Review the database configuration and application logs.\n");
    exit(1);
}
