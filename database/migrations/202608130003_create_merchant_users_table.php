<?php
declare(strict_types=1);
return static function (\PDO $db): void {
    $db->exec("CREATE TABLE merchant_users (id CHAR(36) NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', last_login_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
