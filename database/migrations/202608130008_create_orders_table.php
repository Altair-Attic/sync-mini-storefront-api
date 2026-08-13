<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE orders ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'reference VARCHAR(40) NOT NULL,'
        . 'confirmation_token_hash CHAR(64) NOT NULL,'
        . 'idempotency_key_hash CHAR(64) NOT NULL,'
        . 'request_fingerprint CHAR(64) NOT NULL,'
        . 'customer_name VARCHAR(120) NOT NULL,'
        . 'phone_number VARCHAR(32) NOT NULL,'
        . 'customer_email VARCHAR(254) NULL,'
        . 'fulfilment_method VARCHAR(16) NOT NULL,'
        . 'delivery_address VARCHAR(500) NULL,'
        . 'state VARCHAR(100) NULL,'
        . 'subtotal_kobo BIGINT UNSIGNED NOT NULL,'
        . 'delivery_fee_kobo BIGINT UNSIGNED NOT NULL,'
        . 'total_kobo BIGINT UNSIGNED NOT NULL,'
        . 'currency CHAR(3) NOT NULL,'
        . 'payment_method VARCHAR(32) NOT NULL,'
        . 'payment_status VARCHAR(16) NOT NULL DEFAULT \'unpaid\','
        . 'fulfilment_status VARCHAR(16) NOT NULL DEFAULT \'new\','
        . 'created_at DATETIME NOT NULL,'
        . 'updated_at DATETIME NOT NULL,'
        . 'UNIQUE KEY uq_orders_reference (reference),'
        . 'UNIQUE KEY uq_orders_confirmation_token_hash (confirmation_token_hash),'
        . 'UNIQUE KEY uq_orders_idempotency_key_hash (idempotency_key_hash),'
        . 'KEY idx_orders_payment_status_created (payment_status, created_at),'
        . 'KEY idx_orders_fulfilment_status_created (fulfilment_status, created_at),'
        . 'KEY idx_orders_created_at (created_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
