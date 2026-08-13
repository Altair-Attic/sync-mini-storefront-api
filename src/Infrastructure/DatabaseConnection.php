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
        $this->validate();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->requiredString('db.host'),
            $this->config->port('db.port'),
            $this->config->requiredString('db.database'),
        );

        return new PDO($dsn, $this->config->requiredString('db.username'), $this->config->string('db.password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function validate(): void
    {
        $this->config->requiredString('db.host');
        $this->config->port('db.port');
        $this->config->requiredString('db.database');
        $this->config->requiredString('db.username');
    }
}
