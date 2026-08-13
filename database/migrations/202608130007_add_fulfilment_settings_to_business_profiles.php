<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'ALTER TABLE business_profiles '
        . 'ADD COLUMN pickup_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER delivery_enabled,'
        . 'ADD COLUMN fixed_delivery_fee_kobo INT UNSIGNED NOT NULL DEFAULT 0 AFTER pickup_enabled'
    );
};
