<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE order_status_history ('
        . 'id CHAR(36) NOT NULL PRIMARY KEY,'
        . 'order_id CHAR(36) NOT NULL,'
        . 'previous_status VARCHAR(16) NULL,'
        . 'new_status VARCHAR(16) NOT NULL,'
        . 'changed_by CHAR(36) NULL,'
        . 'created_at DATETIME NOT NULL,'
        . 'KEY idx_order_status_history_order_created (order_id, created_at),'
        . 'KEY idx_order_status_history_changed_by (changed_by),'
        . 'CONSTRAINT fk_order_status_history_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $db->exec('ALTER TABLE notification_jobs MODIFY recipient_type VARCHAR(32) NOT NULL');
};
