<?php

declare(strict_types=1);

namespace ProjectSync\DTO;

final readonly class MerchantBootstrapResult
{
    public function __construct(
        public bool $profileCreated,
        public bool $administratorCreated,
    ) {
    }

    public function isAlreadyInitialized(): bool
    {
        return !$this->profileCreated && !$this->administratorCreated;
    }
}
