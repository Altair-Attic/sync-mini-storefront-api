<?php

declare(strict_types=1);

return static function (\PDO $db): void {
    $db->exec("CREATE TABLE admin_refresh_tokens (id CHAR(36) NOT NULL PRIMARY KEY, merchant_user_id CHAR(36) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, family_id CHAR(36) NOT NULL, expires_at DATETIME NOT NULL, last_used_at DATETIME NULL, revoked_at DATETIME NULL, replaced_by_token_id CHAR(36) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX admin_refresh_tokens_merchant_user_id (merchant_user_id), INDEX admin_refresh_tokens_family_id (family_id), INDEX admin_refresh_tokens_expires_at (expires_at), INDEX admin_refresh_tokens_revoked_at (revoked_at), CONSTRAINT admin_refresh_tokens_user_fk FOREIGN KEY (merchant_user_id) REFERENCES merchant_users(id) ON DELETE CASCADE, CONSTRAINT admin_refresh_tokens_replacement_fk FOREIGN KEY (replaced_by_token_id) REFERENCES admin_refresh_tokens(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
