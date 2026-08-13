<?php
declare(strict_types=1);
return static function (\PDO $db): void {
    $db->exec("CREATE TABLE business_profiles (id CHAR(36) NOT NULL PRIMARY KEY, business_name VARCHAR(120) NOT NULL, slug VARCHAR(80) NOT NULL UNIQUE, domain VARCHAR(253) NOT NULL UNIQUE, whatsapp_number VARCHAR(32) NOT NULL, support_email VARCHAR(254) NULL, logo_url VARCHAR(2048) NULL, template_id VARCHAR(64) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'NGN', timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos', order_confirmation_email BOOLEAN NOT NULL DEFAULT TRUE, whatsapp_handoff BOOLEAN NOT NULL DEFAULT TRUE, delivery_enabled BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
