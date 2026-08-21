<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\ContactValidator;

final class ContactValidatorTest extends TestCase
{
    public function testItNormalizesAValidContactRequest(): void
    {
        self::assertSame([
            'name' => 'Ada Customer',
            'email' => 'ada@example.com',
            'message' => 'Please let me know when this item is available.',
        ], (new ContactValidator())->validate([
            'name' => '  Ada Customer ',
            'email' => ' ADA@EXAMPLE.COM ',
            'message' => ' Please let me know when this item is available. ',
        ]));
    }

    public function testItRejectsInvalidOrUnexpectedFields(): void
    {
        try {
            (new ContactValidator())->validate(['name' => 'A', 'email' => 'invalid', 'message' => 'short', 'role' => 'admin']);
            self::fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('name', $exception->fields);
            self::assertArrayHasKey('email', $exception->fields);
            self::assertArrayHasKey('message', $exception->fields);
            self::assertArrayHasKey('role', $exception->fields);
        }
    }
}
