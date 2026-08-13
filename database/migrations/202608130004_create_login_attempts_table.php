<?php
declare(strict_types=1);
return static function (\PDO $db): void {
    $db->exec("CREATE TABLE login_attempts (id CHAR(36) NOT NULL PRIMARY KEY, identifier_hash CHAR(64) NOT NULL UNIQUE, attempt_count INT UNSIGNED NOT NULL, window_started_at DATETIME NOT NULL, blocked_until DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX login_attempts_blocked_until (blocked_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
