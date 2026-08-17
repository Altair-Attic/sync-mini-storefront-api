<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Services\PaymentReferenceGenerator;

final class PaymentReferenceGeneratorTest extends TestCase
{
    public function testGeneratesValidFormatAndUniqueReferences(): void
    {
        $generator = new PaymentReferenceGenerator();
        $generated = [];

        for ($i = 0; $i < 500; $i++) {
            $ref = $generator->generate();

            self::assertStringStartsWith('PAY-SYNC-', $ref);
            self::assertSame(29, strlen($ref)); // 'PAY-SYNC-' (9) + 20 chars
            self::assertMatchesRegularExpression('/^PAY-SYNC-[0-9A-Z]{20}$/', $ref);

            self::assertArrayNotHasKey($ref, $generated, 'Payment reference must be unique');
            $generated[$ref] = true;
        }
    }
}
