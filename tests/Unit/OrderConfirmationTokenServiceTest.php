<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectSync\Services\OrderConfirmationTokenService;

final class OrderConfirmationTokenServiceTest extends TestCase
{
    public function testCredentialsAreDeterministicDomainSeparatedAndVerifiable(): void
    {
        $service = new OrderConfirmationTokenService(str_repeat('s', 32));
        $hash = $service->idempotencyHash('client-random-key');
        $token = $service->token($hash);

        self::assertSame($hash, $service->idempotencyHash('client-random-key'));
        self::assertNotSame('client-random-key', $hash);
        self::assertSame($token, $service->token($hash));
        self::assertTrue($service->valid($token, $service->tokenHash($token)));
        self::assertFalse($service->valid('wrong', $service->tokenHash($token)));
    }
}
