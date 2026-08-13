<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use ProjectSync\Exceptions\BusinessProfileNotFoundException;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Validators\BusinessProfileValidator;

final readonly class BusinessProfileService
{
    public function __construct(
        private BusinessProfileRepository $profiles,
        private BusinessProfileValidator $validator,
    ) {
    }

    /** @return array{business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string} */
    public function publicProfile(): array
    {
        $profile = $this->profile();

        return [
            'business_name' => $profile['business_name'],
            'slug' => $profile['slug'],
            'domain' => $profile['domain'],
            'whatsapp_number' => $profile['whatsapp_number'],
            'support_email' => $profile['support_email'],
            'logo_url' => $profile['logo_url'],
            'template_id' => $profile['template_id'],
            'currency' => $profile['currency'],
            'timezone' => $profile['timezone'],
        ];
    }

    /** @return array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string} */
    public function adminProfile(): array
    {
        return $this->adminResponse($this->profile());
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string}
     */
    public function update(array $input): array
    {
        $validated = $this->validator->validate($input);
        $current = $this->profile();
        $this->profiles->updateProfile($current['id'], $validated);

        return $this->adminResponse($this->profile());
    }

    /** @return array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string} */
    private function profile(): array
    {
        $profile = $this->profiles->findProfile();
        if ($profile === null) {
            throw new BusinessProfileNotFoundException();
        }

        return $profile;
    }

    /**
     * @param array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string} $profile
     * @return array{id: string, business_name: string, slug: string, domain: string, whatsapp_number: string, support_email: string|null, logo_url: string|null, template_id: string, currency: string, timezone: string, created_at: string, updated_at: string}
     */
    private function adminResponse(array $profile): array
    {
        $profile['created_at'] = $this->utcTimestamp($profile['created_at']);
        $profile['updated_at'] = $this->utcTimestamp($profile['updated_at']);

        return $profile;
    }

    private function utcTimestamp(string $timestamp): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \RuntimeException('Invalid business profile timestamp.');
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }
}
