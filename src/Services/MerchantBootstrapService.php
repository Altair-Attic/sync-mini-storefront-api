<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use PDO;
use ProjectSync\DTO\MerchantBootstrapResult;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Validators\MerchantBootstrapValidator;
use RuntimeException;
use Throwable;

final readonly class MerchantBootstrapService
{
    public function __construct(
        private PDO $db,
        private BusinessProfileRepository $profiles,
        private MerchantUserRepository $users,
        private MerchantBootstrapValidator $validator,
    ) {
    }

    /** @return array{profile_exists: bool, administrator_exists: bool} */
    public function status(): array
    {
        return [
            'profile_exists' => $this->profiles->first() !== null,
            'administrator_exists' => $this->users->first() !== null,
        ];
    }

    /**
     * @param array<string, mixed>|null $profile
     * @param array<string, mixed>|null $administrator
     */
    public function bootstrap(?array $profile, ?array $administrator, ?string $password): MerchantBootstrapResult
    {
        $status = $this->status();
        if ($status['profile_exists'] && $status['administrator_exists']) {
            return new MerchantBootstrapResult(false, false);
        }

        $validatedProfile = $status['profile_exists'] ? null : $this->validator->validateProfile($profile ?? []);
        $validatedAdministrator = $status['administrator_exists'] ? null : $this->validator->validateAdministrator($administrator, $password);

        $passwordHash = null;
        if ($validatedAdministrator !== null) {
            $passwordHash = password_hash($password ?? '', PASSWORD_DEFAULT);
        }

        $this->db->beginTransaction();
        try {
            $current = $this->status();
            if ($current !== $status) {
                throw new RuntimeException('Merchant setup changed while bootstrap was running. Run the command again to inspect the current state.');
            }

            if ($validatedProfile !== null) {
                $this->profiles->createInitialProfile($validatedProfile);
            }
            if ($validatedAdministrator !== null) {
                $this->users->create(UuidGenerator::v4(), $validatedAdministrator['name'], $validatedAdministrator['email'], $passwordHash);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return new MerchantBootstrapResult($validatedProfile !== null, $validatedAdministrator !== null);
    }
}
