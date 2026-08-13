<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE order_items ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'order_id CHAR(36) NOT NULL,'
        . 'product_id CHAR(36) NULL,'
        . 'product_public_id CHAR(36) NOT NULL,'
        . 'product_title VARCHAR(160) NOT NULL,'
        . 'product_slug VARCHAR(180) NOT NULL,'
        . 'unit_price_kobo BIGINT UNSIGNED NOT NULL,'
        . 'quantity INT UNSIGNED NOT NULL,'
        . 'line_total_kobo BIGINT UNSIGNED NOT NULL,'
        . 'created_at DATETIME NOT NULL,'
        . 'KEY idx_order_items_order_id (order_id),'
        . 'KEY idx_order_items_product_id (product_id),'
        . 'CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,'
        . 'CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,'
        . 'CONSTRAINT chk_order_items_quantity CHECK (quantity > 0)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
