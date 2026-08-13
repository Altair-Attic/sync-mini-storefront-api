<?php

declare(strict_types=1);

namespace ProjectSync\Repositories;

use ProjectSync\Infrastructure\UuidGenerator;
use RuntimeException;

final readonly class BusinessProfileRepository
{
    public function __construct(private \PDO $db) {}

    /** @return array{slug: string, domain: string}|null */
    public function first(): ?array
    {
        $statement = $this->db->prepare('SELECT slug, domain FROM business_profiles LIMIT 1');
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (!is_array($row)) throw new RuntimeException('Invalid business profile record.');
        $slug = $row['slug'] ?? null; $domain = $row['domain'] ?? null;
        if (!is_string($slug) || !is_string($domain)) throw new RuntimeException('Invalid business profile record.');
        return ['slug' => $slug, 'domain' => $domain];
    }

    /**
     * @param array{business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string} $business
     * @param array{order_confirmation_email: bool, whatsapp_handoff: bool, delivery_enabled: bool} $settings
     */
    public function create(array $business, array $settings): void
    {
        $statement = $this->db->prepare('INSERT INTO business_profiles (id,business_name,slug,domain,whatsapp_number,support_email,logo_url,template_id,currency,timezone,order_confirmation_email,whatsapp_handoff,delivery_enabled,created_at,updated_at) VALUES (:id,:business_name,:slug,:domain,:whatsapp_number,:support_email,:logo_url,:template_id,:currency,:timezone,:order_confirmation_email,:whatsapp_handoff,:delivery_enabled,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $statement->execute(['id' => UuidGenerator::v4()] + $business + $settings);
    }

    /**
     * @return array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, delivery_enabled: bool, pickup_enabled: bool, fixed_delivery_fee_kobo: int, created_at: string, updated_at: string}|null
     */
    public function findProfile(): ?array
    {
        $statement = $this->db->prepare('SELECT id, business_name, slug, domain, whatsapp_number, support_email, logo_url, template_id, currency, timezone, delivery_enabled, pickup_enabled, fixed_delivery_fee_kobo, created_at, updated_at FROM business_profiles LIMIT 1');
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Invalid business profile record.');
        }

        return $this->profile($row);
    }

    /** @param array{business_name: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, delivery_enabled: bool, pickup_enabled: bool, fixed_delivery_fee_kobo: int} $profile */
    public function updateProfile(string $id, array $profile): void
    {
        $statement = $this->db->prepare('UPDATE business_profiles SET business_name = :business_name, whatsapp_number = :whatsapp_number, support_email = :support_email, logo_url = :logo_url, template_id = :template_id, currency = :currency, timezone = :timezone, delivery_enabled = :delivery_enabled, pickup_enabled = :pickup_enabled, fixed_delivery_fee_kobo = :fixed_delivery_fee_kobo, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute($profile + ['id' => $id]);
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, delivery_enabled: bool, pickup_enabled: bool, fixed_delivery_fee_kobo: int, created_at: string, updated_at: string}
     */
    private function profile(array $row): array
    {
        $required = ['id', 'business_name', 'slug', 'domain', 'whatsapp_number', 'template_id', 'currency', 'timezone', 'created_at', 'updated_at'];
        foreach ($required as $field) {
            if (!isset($row[$field]) || !is_string($row[$field])) {
                throw new RuntimeException('Invalid business profile record.');
            }
        }
        foreach (['support_email', 'logo_url'] as $field) {
            if (isset($row[$field]) && !is_string($row[$field])) {
                throw new RuntimeException('Invalid business profile record.');
            }
        }
        $supportEmail = $row['support_email'] ?? null;
        $logoUrl = $row['logo_url'] ?? null;
        if (!is_string($supportEmail) && $supportEmail !== null) {
            throw new RuntimeException('Invalid business profile record.');
        }
        if (!is_string($logoUrl) && $logoUrl !== null) {
            throw new RuntimeException('Invalid business profile record.');
        }
        $deliveryEnabled = $this->boolean($row, 'delivery_enabled');
        $pickupEnabled = $this->boolean($row, 'pickup_enabled');
        $fixedDeliveryFee = $this->integer($row, 'fixed_delivery_fee_kobo');

        return [
            'id' => $row['id'],
            'business_name' => $row['business_name'],
            'slug' => $row['slug'],
            'domain' => $row['domain'],
            'whatsapp_number' => $row['whatsapp_number'],
            'support_email' => $supportEmail,
            'logo_url' => $logoUrl,
            'template_id' => $row['template_id'],
            'currency' => $row['currency'],
            'timezone' => $row['timezone'],
            'delivery_enabled' => $deliveryEnabled,
            'pickup_enabled' => $pickupEnabled,
            'fixed_delivery_fee_kobo' => $fixedDeliveryFee,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Invalid business profile record.');
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $row */
    private function boolean(array $row, string $field): bool
    {
        $value = $this->integer($row, $field);
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid business profile record.');
        }

        return $value === 1;
    }
}
