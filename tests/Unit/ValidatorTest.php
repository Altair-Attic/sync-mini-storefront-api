<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Validators\Validator;

final class ValidatorTest extends TestCase
{
    public function testItReportsMissingRequiredFields(): void
    {
        $validator = new Validator();

        try {
            $validator->requireFields(['name' => ''], ['name', 'email']);
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame(['name' => ['This field is required.'], 'email' => ['This field is required.']], $exception->fields);
        }
    }
}
