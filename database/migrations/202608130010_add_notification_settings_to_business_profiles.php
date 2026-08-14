<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'ALTER TABLE business_profiles '
        . 'ADD COLUMN order_notification_email VARCHAR(254) NULL AFTER support_email,'
        . 'ADD COLUMN merchant_email_notifications_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER order_notification_email,'
        . 'ADD COLUMN customer_email_notifications_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER merchant_email_notifications_enabled,'
        . 'ADD COLUMN whatsapp_handoff_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER customer_email_notifications_enabled'
    );
};
