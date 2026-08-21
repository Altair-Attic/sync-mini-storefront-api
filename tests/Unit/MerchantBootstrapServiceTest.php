<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Services\MerchantBootstrapService;
use ProjectSync\Validators\BusinessProfileValidator;
use ProjectSync\Validators\MerchantBootstrapValidator;
use RuntimeException;

final class MerchantBootstrapServiceTest extends TestCase
{
    public function testItCreatesProfileAndAdministratorForAnEmptyInstallation(): void
    {
        $events = [];
        $passwordHash = null;
        $service = $this->service($events, [false, false], [false, false], $passwordHash);

        $result = $service->bootstrap($this->profile(), $this->administrator(), 'correct-horse-battery-staple');

        self::assertTrue($result->profileCreated);
        self::assertTrue($result->administratorCreated);
        self::assertSame(['begin', 'profile.create', 'administrator.create', 'commit'], $events);
        self::assertIsString($passwordHash);
        self::assertTrue(password_verify('correct-horse-battery-staple', $passwordHash));
    }

    public function testItPreservesExistingAdministratorWhenOnlyProfileIsMissing(): void
    {
        $events = [];
        $passwordHash = null;
        $service = $this->service($events, [false, false], [true, true], $passwordHash);

        $result = $service->bootstrap($this->profile(), null, null);

        self::assertTrue($result->profileCreated);
        self::assertFalse($result->administratorCreated);
        self::assertSame(['begin', 'profile.create', 'commit'], $events);
        self::assertNull($passwordHash);
    }

    public function testItDoesNothingWhenMerchantSetupIsAlreadyComplete(): void
    {
        $events = [];
        $passwordHash = null;
        $service = $this->service($events, [true], [true], $passwordHash);

        $result = $service->bootstrap(null, null, null);

        self::assertTrue($result->isAlreadyInitialized());
        self::assertSame([], $events);
        self::assertNull($passwordHash);
    }

    public function testInvalidProfileDoesNotBeginATransactionOrWriteData(): void
    {
        $events = [];
        $passwordHash = null;
        $service = $this->service($events, [false], [true], $passwordHash);
        $profile = $this->profile();
        $profile['domain'] = 'not a domain';

        $this->expectException(\ProjectSync\Exceptions\ValidationException::class);
        try {
            $service->bootstrap($profile, null, null);
        } finally {
            self::assertSame([], $events);
            self::assertNull($passwordHash);
        }
    }

    /**
     * @param list<string> $events
     * @param list<bool> $profileStates
     * @param list<bool> $administratorStates
     */
    private function service(array &$events, array $profileStates, array $administratorStates, ?string &$passwordHash): MerchantBootstrapService
    {
        $profileIndex = 0;
        $administratorIndex = 0;
        $inTransaction = false;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(function () use (&$events, &$inTransaction): bool {
            $events[] = 'begin';
            $inTransaction = true;

            return true;
        });
        $pdo->method('commit')->willReturnCallback(function () use (&$events, &$inTransaction): bool {
            $events[] = 'commit';
            $inTransaction = false;

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(function () use (&$events, &$inTransaction): bool {
            $events[] = 'rollback';
            $inTransaction = false;

            return true;
        });
        $pdo->method('inTransaction')->willReturnCallback(static fn (): bool => $inTransaction);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$events, &$profileIndex, &$administratorIndex, $profileStates, $administratorStates, &$passwordHash): PDOStatement {
            $statement = $this->createMock(PDOStatement::class);
            if (str_starts_with($sql, 'SELECT slug, domain FROM business_profiles')) {
                $state = $profileStates[$profileIndex] ?? throw new RuntimeException('Unexpected profile state query.');
                $profileIndex++;
                $statement->method('execute')->willReturn(true);
                $statement->method('fetch')->willReturn($state ? ['slug' => 'demo-store', 'domain' => 'demo.example.com'] : false);

                return $statement;
            }
            if (str_starts_with($sql, 'SELECT email FROM merchant_users')) {
                $state = $administratorStates[$administratorIndex] ?? throw new RuntimeException('Unexpected administrator state query.');
                $administratorIndex++;
                $statement->method('execute')->willReturn(true);
                $statement->method('fetch')->willReturn($state ? ['email' => 'owner@example.com'] : false);

                return $statement;
            }
            if (str_starts_with($sql, 'INSERT INTO business_profiles')) {
                $statement->method('execute')->willReturnCallback(function () use (&$events): bool {
                    $events[] = 'profile.create';

                    return true;
                });

                return $statement;
            }
            if (str_starts_with($sql, 'INSERT INTO merchant_users')) {
                $statement->method('execute')->willReturnCallback(function (array $parameters) use (&$events, &$passwordHash): bool {
                    $events[] = 'administrator.create';
                    $passwordHash = $parameters['password_hash'] ?? null;

                    return true;
                });

                return $statement;
            }

            throw new RuntimeException('Unexpected SQL query.');
        });

        return new MerchantBootstrapService(
            $pdo,
            new BusinessProfileRepository($pdo),
            new MerchantUserRepository($pdo),
            new MerchantBootstrapValidator(new BusinessProfileValidator()),
        );
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return [
            'business_name' => 'Demo Store',
            'slug' => 'demo-store',
            'domain' => 'demo.example.com',
            'whatsapp_number' => '+2348035732952',
            'support_email' => 'support@example.com',
            'order_notification_email' => 'orders@example.com',
            'merchant_email_notifications_enabled' => true,
            'customer_email_notifications_enabled' => false,
            'whatsapp_handoff_enabled' => true,
            'logo_url' => null,
            'template_id' => 'default',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'delivery_enabled' => true,
            'pickup_enabled' => true,
            'fixed_delivery_fee_kobo' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function administrator(): array
    {
        return ['name' => 'Business Owner', 'email' => 'owner@example.com'];
    }
}
