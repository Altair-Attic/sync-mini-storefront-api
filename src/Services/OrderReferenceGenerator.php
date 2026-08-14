<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use DateTimeImmutable;
use DateTimeZone;

final class OrderReferenceGenerator
{
    public function generate(): string
    {
        $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return 'SYNC-' . $date->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
