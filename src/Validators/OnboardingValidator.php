<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final class OnboardingValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $errors = [];
        $this->rejectUnknownTopLevelFields($input, $errors);
        if (($input['schema_version'] ?? null) !== '1.0') $errors['schema_version'] = ['Unsupported schema version.'];
        $business = $this->object($input, 'business', $errors);
        $administrator = $this->object($input, 'administrator', $errors);
        $settings = $this->object($input, 'settings', $errors);
        $this->validateBusiness($business, $errors);
        $this->validateAdministrator($administrator, $errors);
        $this->validateSettings($settings, $errors);
        if ($errors !== []) throw new ValidationException($errors);

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function rejectUnknownTopLevelFields(array $input, array &$errors): void
    {
        foreach ($input as $key => $_) if (!in_array($key, ['schema_version', 'business', 'administrator', 'settings'], true)) $errors[(string) $key] = ['Unknown or forbidden field.'];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     * @return array<string, mixed>
     */
    private function object(array $input, string $key, array &$errors): array
    {
        if (!isset($input[$key]) || !is_array($input[$key])) { $errors[$key] = ['This object is required.']; return []; }
        $object = $input[$key];
        $normalized = [];
        foreach ($object as $field => $value) if (is_string($field)) $normalized[$field] = $value;
        return $normalized;
    }

    /**
     * @param array<string, mixed> $business
     * @param array<string, list<string>> $errors
     */
    private function validateBusiness(array $business, array &$errors): void
    {
        foreach (['business_name', 'slug', 'domain', 'whatsapp_number', 'currency', 'timezone', 'template_id'] as $key) if (!is_string($business[$key] ?? null) || trim($business[$key]) === '') $errors['business.' . $key] = ['This field is required.'];
        $name = $business['business_name'] ?? null;
        if (is_string($name) && (mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 120)) $errors['business.business_name'] = ['Use between 2 and 120 characters.'];
        if (is_string($business['slug'] ?? null) && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $business['slug'])) $errors['business.slug'] = ['Enter a valid slug.'];
        if (is_string($business['domain'] ?? null) && filter_var($business['domain'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) $errors['business.domain'] = ['Enter a valid domain.'];
        if (is_string($business['whatsapp_number'] ?? null) && !preg_match('/^\+[1-9][0-9]{7,14}$/', $business['whatsapp_number'])) $errors['business.whatsapp_number'] = ['Enter an international phone number.'];
        $supportEmail = $business['support_email'] ?? null;
        if ($supportEmail !== null && (!is_string($supportEmail) || filter_var($supportEmail, FILTER_VALIDATE_EMAIL) === false)) $errors['business.support_email'] = ['Enter a valid email address.'];
        if (($business['currency'] ?? null) !== 'NGN') $errors['business.currency'] = ['Unsupported currency.'];
        if (is_string($business['timezone'] ?? null) && !in_array($business['timezone'], timezone_identifiers_list(), true)) $errors['business.timezone'] = ['Enter a valid timezone.'];
        if (is_string($business['template_id'] ?? null) && !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $business['template_id'])) $errors['business.template_id'] = ['Enter a valid template identifier.'];
    }

    /**
     * @param array<string, mixed> $administrator
     * @param array<string, list<string>> $errors
     */
    private function validateAdministrator(array $administrator, array &$errors): void
    {
        if (!is_string($administrator['name'] ?? null) || trim($administrator['name']) === '') $errors['administrator.name'] = ['This field is required.'];
        $email = $administrator['email'] ?? null;
        if (!is_string($email) || filter_var(strtolower(trim($email)), FILTER_VALIDATE_EMAIL) === false) $errors['administrator.email'] = ['Enter a valid email.'];
        foreach ($administrator as $key => $_) if (in_array(strtolower((string) $key), ['password', 'password_hash', 'secret', 'api_key', 'db_password'], true)) $errors['administrator.' . $key] = ['Forbidden field.'];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, list<string>> $errors
     */
    private function validateSettings(array $settings, array &$errors): void
    {
        foreach (['order_confirmation_email', 'whatsapp_handoff', 'delivery_enabled'] as $key) if (!array_key_exists($key, $settings) || !is_bool($settings[$key])) $errors['settings.' . $key] = ['This field must be a boolean.'];
    }
}
