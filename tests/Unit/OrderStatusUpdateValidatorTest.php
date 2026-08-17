<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\OrderStatusUpdateValidator;

final class OrderStatusUpdateValidatorTest extends TestCase
{
    private OrderStatusUpdateValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OrderStatusUpdateValidator();
    }

    /** @param string $status */
    #[\PHPUnit\Framework\Attributes\DataProvider('validStatuses')]
    public function testValidStatusPasses(string $status): void
    {
        $result = $this->validator->validate(['status' => $status]);
        self::assertSame(strtolower(trim($status)), $result['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function validStatuses(): iterable
    {
        yield 'new' => ['new'];
        yield 'confirmed' => ['confirmed'];
        yield 'processing' => ['processing'];
        yield 'ready' => ['ready'];
        yield 'completed' => ['completed'];
        yield 'cancelled' => ['cancelled'];
        yield 'uppercase' => ['CONFIRMED'];
        yield 'whitespace' => [' ready '];
    }

    public function testMissingStatusIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate([]);
    }

    public function testUnknownFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['status' => 'confirmed', 'unknown' => 'value']);
    }

    /** @param mixed $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStatuses')]
    public function testInvalidStatusIsRejected(mixed $value): void
    {
        try {
            $this->validator->validate(['status' => $value]);
            self::fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('status', $exception->fields);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidStatuses(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'pending deprecated' => ['pending'];
        yield 'invalid status name' => ['shipped'];
        yield 'integer' => [123];
        yield 'boolean' => [true];
        yield 'array' => [['status']];
        yield 'null' => [null];
    }
}
