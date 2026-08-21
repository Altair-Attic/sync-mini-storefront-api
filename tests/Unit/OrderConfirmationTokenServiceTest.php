<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Services\OrderConfirmationTokenService;

final class OrderConfirmationTokenServiceTest extends TestCase
{
    public function testGeneratedTokensAreRandomAndVerifiableAgainstOnlyTheirHash(): void
    {
        $service = new OrderConfirmationTokenService();
        $first = $service->generate();
        $second = $service->generate();

        self::assertNotSame($first, $second);
        self::assertTrue($service->valid($first, $service->tokenHash($first)));
        self::assertFalse($service->valid($second, $service->tokenHash($first)));
    }
}
