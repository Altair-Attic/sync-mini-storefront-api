<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'ALTER TABLE products '
        . 'ADD COLUMN stock_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_available, '
        . 'ADD KEY idx_products_stock_quantity (stock_quantity)'
    );
};
