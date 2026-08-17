<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE payment_events ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'payment_attempt_id CHAR(36) NULL,'
        . 'order_id CHAR(36) NULL,'
        . 'provider VARCHAR(32) NOT NULL DEFAULT \'paystack\','
        . 'event_type VARCHAR(64) NOT NULL,'
        . 'provider_reference VARCHAR(100) NOT NULL,'
        . 'payload_hash CHAR(64) NOT NULL,'
        . 'processing_status VARCHAR(16) NOT NULL,'
        . 'processing_notes VARCHAR(255) NULL,'
        . 'created_at DATETIME NOT NULL,'
        . 'UNIQUE KEY uq_payment_events_provider_type_ref (provider, event_type, provider_reference),'
        . 'KEY idx_payment_events_attempt (payment_attempt_id),'
        . 'KEY idx_payment_events_order (order_id),'
        . 'KEY idx_payment_events_created_at (created_at),'
        . 'KEY idx_payment_events_status (processing_status),'
        . 'CONSTRAINT fk_payment_events_attempt FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts (id) ON DELETE RESTRICT,'
        . 'CONSTRAINT fk_payment_events_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
