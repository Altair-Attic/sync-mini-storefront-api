<?php

declare(strict_types=1);

return static function (\PDO $db): void {
    $db->exec("CREATE TABLE revoked_access_tokens (jwt_id CHAR(36) NOT NULL PRIMARY KEY, merchant_user_id CHAR(36) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX revoked_access_tokens_expires_at (expires_at), CONSTRAINT revoked_access_tokens_user_fk FOREIGN KEY (merchant_user_id) REFERENCES merchant_users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
