<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\OnboardingValidator;

final class OnboardingValidatorTest extends TestCase
{
    public function testItAcceptsTheDocumentedOnboardingPayload(): void
    {
        $payload = $this->validPayload();

        self::assertSame($payload, (new OnboardingValidator())->validate($payload));
    }

    public function testItRejectsForbiddenAdministratorPasswordFields(): void
    {
        $payload = $this->validPayload();
        $administrator = $payload['administrator'] ?? null;
        if (!is_array($administrator)) self::fail('Fixture administrator is invalid.');
        $administrator['password'] = 'not-allowed';
        $payload['administrator'] = $administrator;

        try {
            (new OnboardingValidator())->validate($payload);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            self::assertSame(['Forbidden field.'], $exception->fields['administrator.password']);
        }
    }

    public function testItRejectsAnInvalidPhoneNumber(): void
    {
        $payload = $this->validPayload();
        $business = $payload['business'] ?? null;
        if (!is_array($business)) self::fail('Fixture business is invalid.');
        $business['whatsapp_number'] = '09035732952';
        $payload['business'] = $business;

        $this->expectException(ValidationException::class);
        (new OnboardingValidator())->validate($payload);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/onboarding.example.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \RuntimeException('Onboarding fixture must decode to an object.');
        $payload = [];
        foreach ($decoded as $key => $value) if (is_string($key)) $payload[$key] = $value;
        return $payload;
    }
}
