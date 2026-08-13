<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use PDO;

final readonly class DatabaseConnection
{
    public function __construct(private Config $config)
    {
    }

    public function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->string('db.host'),
            (int) $this->config->string('db.port'),
            $this->config->string('db.database'),
        );

        return new PDO($dsn, $this->config->string('db.username'), $this->config->string('db.password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
