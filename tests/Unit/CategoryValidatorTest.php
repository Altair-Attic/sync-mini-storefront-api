<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\CategoryValidator;

final class CategoryValidatorTest extends TestCase
{
    public function testNormalizesFieldsAndGeneratesSlug(): void
    {
        $result = (new CategoryValidator())->validate(['name' => '  Fresh Foods  ', 'description' => '  Local  ']);

        self::assertSame('Fresh Foods', $result['name']);
        self::assertSame('fresh-foods', $result['slug']);
        self::assertSame('Local', $result['description']);
        self::assertSame(0, $result['display_order']);
        self::assertTrue($result['is_active']);
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidAndForbiddenFields(string $field, mixed $value): void
    {
        try {
            (new CategoryValidator())->validate(['name' => 'Valid Name', $field => $value]);
            self::fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->fields);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'invalid name' => ['name', 'x'];
        yield 'invalid slug' => ['slug', 'Not Safe'];
        yield 'negative order' => ['display_order', -1];
        yield 'non integer order' => ['display_order', '1'];
        yield 'non boolean active' => ['is_active', 1];
        yield 'long description' => ['description', str_repeat('x', 1001)];
        yield 'unknown field' => ['unexpected', true];
        yield 'immutable field' => ['public_id', 'id'];
    }
}
