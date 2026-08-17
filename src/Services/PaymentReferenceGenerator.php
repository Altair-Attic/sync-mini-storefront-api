<?php

declare(strict_types=1);

namespace ProjectSync\Services;

final class PaymentReferenceGenerator
{
    private const string PREFIX = 'PAY-SYNC-';
    private const string ALPHABET = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function generate(): string
    {
        $bytes = random_bytes(20);
        $alphabetLength = strlen(self::ALPHABET);
        $random = '';
        for ($i = 0; $i < 20; $i++) {
            $random .= self::ALPHABET[ord($bytes[$i]) % $alphabetLength];
        }

        return self::PREFIX . $random;
    }
}
