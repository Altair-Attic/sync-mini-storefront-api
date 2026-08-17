<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE payment_attempts ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'order_id CHAR(36) NOT NULL,'
        . 'provider VARCHAR(32) NOT NULL DEFAULT \'paystack\','
        . 'internal_reference VARCHAR(64) NOT NULL,'
        . 'provider_reference VARCHAR(100) NULL,'
        . 'access_code VARCHAR(100) NULL,'
        . 'authorization_url VARCHAR(500) NULL,'
        . 'idempotency_key_hash CHAR(64) NOT NULL,'
        . 'expected_amount_kobo BIGINT UNSIGNED NOT NULL,'
        . 'verified_amount_kobo BIGINT UNSIGNED NULL,'
        . 'currency CHAR(3) NOT NULL DEFAULT \'NGN\','
        . 'status VARCHAR(16) NOT NULL DEFAULT \'initialized\','
        . 'resolution_status VARCHAR(32) NOT NULL DEFAULT \'none\','
        . 'provider_status VARCHAR(32) NULL,'
        . 'channel VARCHAR(32) NULL,'
        . 'initiated_at DATETIME NOT NULL,'
        . 'finalized_at DATETIME NULL,'
        . 'created_at DATETIME NOT NULL,'
        . 'updated_at DATETIME NOT NULL,'
        . 'UNIQUE KEY uq_payment_attempts_internal_ref (internal_reference),'
        . 'UNIQUE KEY uq_payment_attempts_order_idempotency (order_id, idempotency_key_hash),'
        . 'KEY idx_payment_attempts_order (order_id),'
        . 'KEY idx_payment_attempts_provider_ref (provider, provider_reference),'
        . 'KEY idx_payment_attempts_status_initiated (status, initiated_at),'
        . 'KEY idx_payment_attempts_resolution (resolution_status),'
        . 'CONSTRAINT fk_payment_attempts_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
