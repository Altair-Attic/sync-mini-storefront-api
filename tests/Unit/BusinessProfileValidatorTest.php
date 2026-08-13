<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\BusinessProfileValidator;

final class BusinessProfileValidatorTest extends TestCase
{
    public function testItNormalizesValuesBeforePersistence(): void
    {
        $input = $this->validInput();
        $input['business_name'] = '  Demo Store  ';
        $input['whatsapp_number'] = '0803 573 2952';
        $input['support_email'] = ' OWNER@EXAMPLE.COM ';
        $input['template_id'] = ' CLASSIC_ONE ';
        $input['currency'] = ' ngn ';

        self::assertSame([
            'business_name' => 'Demo Store',
            'whatsapp_number' => '+2348035732952',
            'support_email' => 'owner@example.com',
            'logo_url' => 'https://cdn.example.com/logo.png',
            'template_id' => 'classic_one',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ], (new BusinessProfileValidator())->validate($input));
    }

    /** @param mixed $value */
    #[DataProvider('invalidFields')]
    public function testItRejectsInvalidFields(string $field, mixed $value): void
    {
        $input = $this->validInput();
        $input[$field] = $value;

        try {
            (new BusinessProfileValidator())->validate($input);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->fields);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'missing business name' => ['business_name', ''];
        yield 'invalid email' => ['support_email', 'invalid-email'];
        yield 'invalid phone' => ['whatsapp_number', '12345'];
        yield 'invalid currency' => ['currency', 'USD'];
        yield 'invalid timezone' => ['timezone', 'Lagos/Local'];
        yield 'HTTP logo URL' => ['logo_url', 'http://example.com/logo.png'];
        yield 'invalid template' => ['template_id', '../classic'];
    }

    public function testItRejectsUnknownAndIdentityFields(): void
    {
        $input = $this->validInput() + ['slug' => 'replacement', 'domain' => 'other.example.com', 'mystery' => true];

        try {
            (new BusinessProfileValidator())->validate($input);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            self::assertSame(['Unknown or forbidden field.'], $exception->fields['slug']);
            self::assertSame(['Unknown or forbidden field.'], $exception->fields['domain']);
            self::assertSame(['Unknown or forbidden field.'], $exception->fields['mystery']);
        }
    }

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        return [
            'business_name' => 'Demo Store',
            'whatsapp_number' => '+2348035732952',
            'support_email' => 'owner@example.com',
            'logo_url' => 'https://cdn.example.com/logo.png',
            'template_id' => 'classic_one',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ];
    }
}
