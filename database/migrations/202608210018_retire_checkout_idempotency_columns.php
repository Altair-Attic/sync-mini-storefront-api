<?php

declare(strict_types=1);

return static function (\PDO $db): void {
    $db->exec('ALTER TABLE orders DROP INDEX uq_orders_idempotency_key_hash');
    $db->exec('ALTER TABLE orders MODIFY idempotency_key_hash CHAR(64) NULL, MODIFY request_fingerprint CHAR(64) NULL');
};
