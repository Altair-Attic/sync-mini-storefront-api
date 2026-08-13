<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE categories ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'public_id CHAR(36) NOT NULL,'
        . 'name VARCHAR(100) NOT NULL,'
        . 'slug VARCHAR(120) NOT NULL,'
        . 'description VARCHAR(1000) NULL,'
        . 'display_order INT UNSIGNED NOT NULL DEFAULT 0,'
        . 'is_active BOOLEAN NOT NULL DEFAULT TRUE,'
        . 'created_at DATETIME NOT NULL,'
        . 'updated_at DATETIME NOT NULL,'
        . 'UNIQUE KEY uq_categories_public_id (public_id),'
        . 'UNIQUE KEY uq_categories_slug (slug),'
        . 'KEY idx_categories_active_order_name (is_active, display_order, name)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
