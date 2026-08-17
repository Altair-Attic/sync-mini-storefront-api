<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'ALTER TABLE products '
        . 'ADD COLUMN is_available BOOLEAN NOT NULL DEFAULT TRUE AFTER is_active, '
        . 'ADD KEY idx_products_admin_availability (is_available, updated_at)'
    );
};
