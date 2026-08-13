<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE products ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'public_id CHAR(36) NOT NULL,'
        . 'category_id CHAR(36) NULL,'
        . 'slug VARCHAR(180) NOT NULL,'
        . 'title VARCHAR(160) NOT NULL,'
        . 'description TEXT NULL,'
        . 'price_kobo INT UNSIGNED NOT NULL,'
        . 'image_url VARCHAR(2048) NULL,'
        . 'is_active BOOLEAN NOT NULL DEFAULT TRUE,'
        . 'display_order INT UNSIGNED NOT NULL DEFAULT 0,'
        . 'created_at DATETIME NOT NULL,'
        . 'updated_at DATETIME NOT NULL,'
        . 'UNIQUE KEY uq_products_public_id (public_id),'
        . 'UNIQUE KEY uq_products_slug (slug),'
        . 'KEY idx_products_public_listing (is_active, display_order, title),'
        . 'KEY idx_products_category_listing (category_id, is_active, display_order),'
        . 'KEY idx_products_admin_status (is_active, updated_at),'
        . 'KEY idx_products_created_at (created_at),'
        . 'CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
