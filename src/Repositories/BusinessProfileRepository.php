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
}
