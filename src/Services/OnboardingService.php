<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use PDO;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Validators\OnboardingValidator;
use RuntimeException;
use Throwable;

final readonly class OnboardingService
{
    public function __construct(
        private PDO $db,
        private BusinessProfileRepository $profiles,
        private MerchantUserRepository $users,
        private OnboardingValidator $validator,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function onboard(array $payload, string $password): void
    {
        $validated = $this->validator->validate($payload);
        $business = $this->business($validated);
        $administrator = $this->administrator($validated);
        $settings = $this->settings($validated);
        $this->validatePassword($password);

        $this->db->beginTransaction();

        try {
            $existingBusiness = $this->profiles->first();
            if ($existingBusiness !== null && ($existingBusiness['slug'] !== $business['slug'] || $existingBusiness['domain'] !== $business['domain'])) {
                throw new RuntimeException('Business profile conflict.');
            }

            $existingAdministrator = $this->users->first();
            if ($existingAdministrator !== null && $existingAdministrator['email'] !== $administrator['email']) {
                throw new RuntimeException('Administrator conflict.');
            }

            if ($existingBusiness === null) $this->profiles->create($business, $settings);
            if ($existingAdministrator === null) {
                $this->users->create(UuidGenerator::v4(), $administrator['name'], $administrator['email'], password_hash($password, PASSWORD_DEFAULT));
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string}
     */
    private function business(array $payload): array
    {
        $business = $this->arrayValue($payload, 'business');

        return [
            'business_name' => $this->trimmedString($business, 'business_name'),
            'slug' => $this->trimmedString($business, 'slug'),
            'domain' => $this->trimmedString($business, 'domain'),
            'whatsapp_number' => $this->trimmedString($business, 'whatsapp_number'),
            'support_email' => $this->nullableTrimmedString($business, 'support_email'),
            'logo_url' => $this->nullableTrimmedString($business, 'logo_url'),
            'template_id' => $this->trimmedString($business, 'template_id'),
            'currency' => $this->trimmedString($business, 'currency'),
            'timezone' => $this->trimmedString($business, 'timezone'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name: string, email: string}
     */
    private function administrator(array $payload): array
    {
        $administrator = $this->arrayValue($payload, 'administrator');

        return ['name' => $this->trimmedString($administrator, 'name'), 'email' => strtolower($this->trimmedString($administrator, 'email'))];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{order_confirmation_email: bool, whatsapp_handoff: bool, delivery_enabled: bool}
     */
    private function settings(array $payload): array
    {
        $settings = $this->arrayValue($payload, 'settings');

        return [
            'order_confirmation_email' => $this->boolValue($settings, 'order_confirmation_email'),
            'whatsapp_handoff' => $this->boolValue($settings, 'whatsapp_handoff'),
            'delivery_enabled' => $this->boolValue($settings, 'delivery_enabled'),
        ];
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 12) throw new RuntimeException('An onboarding password is required.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function arrayValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) throw new RuntimeException('Validated onboarding payload is invalid.');

        $result = [];
        foreach ($value as $field => $fieldValue) if (is_string($field)) $result[$field] = $fieldValue;

        return $result;
    }

    /** @param array<string, mixed> $values */
    private function trimmedString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) throw new RuntimeException('Validated onboarding payload is invalid.');

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private function nullableTrimmedString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null) return null;
        if (!is_string($value)) throw new RuntimeException('Validated onboarding payload is invalid.');

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private function boolValue(array $values, string $key): bool
    {
        $value = $values[$key] ?? null;
        if (!is_bool($value)) throw new RuntimeException('Validated onboarding payload is invalid.');

        return $value;
    }
}
