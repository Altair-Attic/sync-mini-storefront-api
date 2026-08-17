<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\OrderListQueryValidator;

final class OrderListQueryValidatorTest extends TestCase
{
    private OrderListQueryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OrderListQueryValidator();
    }

    public function testDefaultQueryValues(): void
    {
        $result = $this->validator->validate([]);

        self::assertSame(1, $result['page']);
        self::assertSame(20, $result['per_page']);
        self::assertNull($result['status']);
        self::assertNull($result['search']);
        self::assertSame('newest', $result['sort']);
        self::assertNull($result['date_from']);
        self::assertNull($result['date_to']);
    }

    public function testCustomValidQueryValues(): void
    {
        $result = $this->validator->validate([
            'page' => '3',
            'per_page' => '50',
            'status' => 'confirmed',
            'search' => 'John Doe',
            'sort' => 'total_high',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-15',
        ]);

        self::assertSame(3, $result['page']);
        self::assertSame(50, $result['per_page']);
        self::assertSame('confirmed', $result['status']);
        self::assertSame('John Doe', $result['search']);
        self::assertSame('total_high', $result['sort']);
        self::assertSame('2026-08-01 00:00:00', $result['date_from']);
        self::assertSame('2026-08-15 00:00:00', $result['date_to']);
    }

    public function testIsoDateTimeParsing(): void
    {
        $result = $this->validator->validate([
            'date_from' => '2026-08-01T10:00:00Z',
            'date_to' => '2026-08-15T18:30:00Z',
        ]);

        self::assertSame('2026-08-01 10:00:00', $result['date_from']);
        self::assertSame('2026-08-15 18:30:00', $result['date_to']);
    }

    public function testDateFromAfterDateToIsRejected(): void
    {
        try {
            $this->validator->validate([
                'date_from' => '2026-08-20',
                'date_to' => '2026-08-10',
            ]);
            self::fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('date_from', $exception->fields);
        }
    }

    /** @param mixed $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQueryFields')]
    public function testInvalidFieldsAreRejected(string $field, mixed $value): void
    {
        try {
            $this->validator->validate([$field => $value]);
            self::fail("Expected ValidationException for {$field}.");
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->fields);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidQueryFields(): iterable
    {
        yield 'page zero' => ['page', '0'];
        yield 'page negative' => ['page', '-1'];
        yield 'page text' => ['page', 'abc'];
        yield 'per_page zero' => ['per_page', '0'];
        yield 'per_page exceeded' => ['per_page', '101'];
        yield 'invalid status' => ['status', 'invalid_status'];
        yield 'pending status rejected' => ['status', 'pending'];
        yield 'search too long' => ['search', str_repeat('a', 101)];
        yield 'invalid sort' => ['sort', 'unknown_sort'];
        yield 'invalid date_from' => ['date_from', 'not-a-date'];
        yield 'invalid date_to' => ['date_to', 'bad-date'];
        yield 'unknown parameter' => ['unknown_filter', 'val'];
    }
}
