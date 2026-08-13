<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ProjectSync\Exceptions\ValidationException;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Services\OnboardingService;
use ProjectSync\Validators\OnboardingValidator;
use RuntimeException;

final class OnboardingServiceTest extends TestCase
{
    public function testSuccessfulOnboardingCreatesBusinessAndAdministrator(): void
    {
        $events = [];
        $service = $this->service($events);

        $service->onboard($this->validPayload(), 'temporary-strong-password');

        self::assertSame(['begin', 'business.create', 'administrator.create', 'commit'], $events);
    }

    public function testSuccessfulOnboardingHashesTheAdministratorPassword(): void
    {
        $events = [];
        $passwordHash = null;
        $service = $this->service($events, passwordHash: $passwordHash);

        $service->onboard($this->validPayload(), 'temporary-strong-password');

        self::assertIsString($passwordHash);
        self::assertTrue(password_verify('temporary-strong-password', $passwordHash));
    }

    public function testSuccessfulOnboardingCommitsTheTransaction(): void
    {
        $events = [];
        $service = $this->service($events);

        $service->onboard($this->validPayload(), 'temporary-strong-password');

        self::assertContains('commit', $events);
        self::assertNotContains('rollback', $events);
    }

    public function testBusinessCreationFailureRollsBackTheTransaction(): void
    {
        $events = [];
        $service = $this->service($events, businessCreationFailure: new RuntimeException('Database error.'));

        $this->expectException(RuntimeException::class);
        try {
            $service->onboard($this->validPayload(), 'temporary-strong-password');
        } finally {
            self::assertSame(['begin', 'business.create', 'rollback'], $events);
        }
    }

    public function testAdministratorCreationFailureRollsBackTheTransaction(): void
    {
        $events = [];
        $service = $this->service($events, administratorCreationFailure: new RuntimeException('Database error.'));

        $this->expectException(RuntimeException::class);
        try {
            $service->onboard($this->validPayload(), 'temporary-strong-password');
        } finally {
            self::assertSame(['begin', 'business.create', 'administrator.create', 'rollback'], $events);
        }
    }

    public function testIdenticalRepeatCreatesNothing(): void
    {
        $events = [];
        $service = $this->service($events, existingBusiness: ['slug' => 'demo-store', 'domain' => 'demo.sync.business'], existingAdministrator: ['email' => 'owner@example.com']);

        $service->onboard($this->validPayload(), 'temporary-strong-password');

        self::assertSame(['begin', 'commit'], $events);
    }

    public function testIdenticalRepeatDoesNotResetThePassword(): void
    {
        $events = [];
        $passwordHash = null;
        $service = $this->service($events, existingBusiness: ['slug' => 'demo-store', 'domain' => 'demo.sync.business'], existingAdministrator: ['email' => 'owner@example.com'], passwordHash: $passwordHash);

        $service->onboard($this->validPayload(), 'replacement-password');

        self::assertNull($passwordHash);
        self::assertNotContains('administrator.create', $events);
    }

    public function testBusinessConflictRollsBackWithoutCreatingRecords(): void
    {
        $events = [];
        $service = $this->service($events, existingBusiness: ['slug' => 'other-store', 'domain' => 'other.sync.business']);

        $this->expectExceptionMessage('Business profile conflict.');
        try {
            $service->onboard($this->validPayload(), 'temporary-strong-password');
        } finally {
            self::assertSame(['begin', 'rollback'], $events);
        }
    }

    public function testAdministratorConflictRollsBackWithoutCreatingRecords(): void
    {
        $events = [];
        $service = $this->service($events, existingBusiness: ['slug' => 'demo-store', 'domain' => 'demo.sync.business'], existingAdministrator: ['email' => 'other@example.com']);

        $this->expectExceptionMessage('Administrator conflict.');
        try {
            $service->onboard($this->validPayload(), 'temporary-strong-password');
        } finally {
            self::assertSame(['begin', 'rollback'], $events);
        }
    }

    public function testValidationFailurePerformsNoWrites(): void
    {
        $events = [];
        $service = $this->service($events);
        $payload = $this->validPayload();
        unset($payload['business']);

        $this->expectException(ValidationException::class);
        try {
            $service->onboard($payload, 'temporary-strong-password');
        } finally {
            self::assertSame([], $events);
        }
    }

    /**
     * @param list<string> $events
     * @param array{slug: string, domain: string}|null $existingBusiness
     * @param array{email: string}|null $existingAdministrator
     */
    private function service(array &$events, ?array $existingBusiness = null, ?array $existingAdministrator = null, ?RuntimeException $businessCreationFailure = null, ?RuntimeException $administratorCreationFailure = null, ?string &$passwordHash = null): OnboardingService
    {
        $inTransaction = false;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(function () use (&$events, &$inTransaction): bool { $events[] = 'begin'; $inTransaction = true; return true; });
        $pdo->method('commit')->willReturnCallback(function () use (&$events, &$inTransaction): bool { $events[] = 'commit'; $inTransaction = false; return true; });
        $pdo->method('rollBack')->willReturnCallback(function () use (&$events, &$inTransaction): bool { $events[] = 'rollback'; $inTransaction = false; return true; });
        $pdo->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool { return $inTransaction; });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$events, $existingBusiness, $existingAdministrator, $businessCreationFailure, $administratorCreationFailure, &$passwordHash): PDOStatement {
            $statement = $this->createMock(PDOStatement::class);
            if (str_starts_with($sql, 'SELECT slug, domain')) {
                $statement->method('execute')->willReturn(true);
                $statement->method('fetch')->willReturn($existingBusiness ?? false);
                return $statement;
            }
            if (str_starts_with($sql, 'SELECT email FROM merchant_users')) {
                $statement->method('execute')->willReturn(true);
                $statement->method('fetch')->willReturn($existingAdministrator ?? false);
                return $statement;
            }
            if (str_starts_with($sql, 'INSERT INTO business_profiles')) {
                $statement->method('execute')->willReturnCallback(function () use (&$events, $businessCreationFailure): bool { $events[] = 'business.create'; if ($businessCreationFailure !== null) throw $businessCreationFailure; return true; });
                return $statement;
            }
            if (str_starts_with($sql, 'INSERT INTO merchant_users')) {
                $statement->method('execute')->willReturnCallback(function (array $parameters) use (&$events, $administratorCreationFailure, &$passwordHash): bool { $events[] = 'administrator.create'; $passwordHash = $parameters['password_hash'] ?? null; if ($administratorCreationFailure !== null) throw $administratorCreationFailure; return true; });
                return $statement;
            }

            throw new RuntimeException('Unexpected SQL query.');
        });

        return new OnboardingService($pdo, new BusinessProfileRepository($pdo), new MerchantUserRepository($pdo), new OnboardingValidator());
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        $payload = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/onboarding.example.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Onboarding fixture must decode to an object.');

        $result = [];
        foreach ($payload as $key => $value) if (is_string($key)) $result[$key] = $value;

        return $result;
    }
}
