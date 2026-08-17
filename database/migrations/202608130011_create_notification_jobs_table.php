<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE notification_jobs ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'order_id CHAR(36) NOT NULL,'
        . 'channel VARCHAR(16) NOT NULL,'
        . 'recipient_type VARCHAR(32) NOT NULL,'
        . 'recipient_hash CHAR(64) NULL,'
        . 'status VARCHAR(16) NOT NULL,'
        . 'attempts INT UNSIGNED NOT NULL DEFAULT 0,'
        . 'max_attempts INT UNSIGNED NOT NULL,'
        . 'available_at DATETIME NOT NULL,'
        . 'processing_started_at DATETIME NULL,'
        . 'last_attempt_at DATETIME NULL,'
        . 'sent_at DATETIME NULL,'
        . 'last_error_code VARCHAR(64) NULL,'
        . 'created_at DATETIME NOT NULL,'
        . 'updated_at DATETIME NOT NULL,'
        . 'UNIQUE KEY uq_notification_jobs_recipient (order_id, channel, recipient_type),'
        . 'KEY idx_notification_jobs_due (status, available_at, attempts),'
        . 'KEY idx_notification_jobs_order (order_id),'
        . 'KEY idx_notification_jobs_status_attempts (status, attempts),'
        . 'CONSTRAINT fk_notification_jobs_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
