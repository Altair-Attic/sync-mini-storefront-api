<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use ProjectSync\Exceptions\ValidationException;

final readonly class MerchantBootstrapValidator
{
    public function __construct(private BusinessProfileValidator $profiles)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, order_notification_email: string|null, merchant_email_notifications_enabled: bool, customer_email_notifications_enabled: bool, whatsapp_handoff_enabled: bool, logo_url: string|null, template_id: string, currency: string, timezone: string, delivery_enabled: bool, pickup_enabled: bool, fixed_delivery_fee_kobo: int}
     */
    public function validateProfile(array $input): array
    {
        $errors = [];
        $slug = $this->string($input['slug'] ?? null);
        if ($slug === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors['slug'] = ['Enter a valid slug.'];
        }
        $domain = $this->string($input['domain'] ?? null);
        if ($domain === null || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            $errors['domain'] = ['Enter a valid domain.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $profileInput = $input;
        unset($profileInput['slug'], $profileInput['domain']);
        $profile = $this->profiles->validate($profileInput);

        if ($slug === null || $domain === null) {
            throw new \LogicException('Validated bootstrap profile has invalid identity fields.');
        }

        return ['slug' => $slug, 'domain' => strtolower($domain)] + $profile;
    }

    /**
     * @param array<string, mixed>|null $administrator
     * @return array{name: string, email: string}
     */
    public function validateAdministrator(?array $administrator, ?string $password): array
    {
        $errors = [];
        $name = $this->string($administrator['name'] ?? null);
        if ($name === null || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['administrator.name'] = ['Use between 2 and 120 characters.'];
        }
        $email = $this->string($administrator['email'] ?? null);
        if ($email === null || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['administrator.email'] = ['Enter a valid email address.'];
        }
        if (!is_string($password) || strlen($password) < 12) {
            $errors['administrator.password'] = ['Use at least 12 characters.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($name === null || $email === null) {
            throw new \LogicException('Validated bootstrap administrator has invalid fields.');
        }

        return ['name' => $name, 'email' => strtolower($email)];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
