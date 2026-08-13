<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use PDO;
use RuntimeException;

final readonly class MigrationRunner
{
    public function __construct(private PDO $connection, private string $migrationPath)
    {
    }

    /** @return list<string> */
    public function run(): array
    {
        $this->connection->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(255) NOT NULL PRIMARY KEY, batch INT UNSIGNED NOT NULL, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $appliedStatement = $this->connection->prepare('SELECT migration FROM schema_migrations');
        $appliedStatement->execute();
        /** @var list<string> $applied */
        $applied = $appliedStatement->fetchAll(PDO::FETCH_COLUMN);
        $batchStatement = $this->connection->prepare('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations');
        $batchStatement->execute();
        $batch = (int) $batchStatement->fetchColumn();
        $executed = [];

        $files = glob($this->migrationPath . '/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $migration = basename($file, '.php');
            if (in_array($migration, $applied, true)) {
                continue;
            }

            $callback = require $file;
            if (!is_callable($callback)) {
                throw new RuntimeException(sprintf('Migration "%s" must return a callable.', $migration));
            }

            $this->connection->beginTransaction();
            try {
                $callback($this->connection);
                $statement = $this->connection->prepare('INSERT INTO schema_migrations (migration, batch) VALUES (:migration, :batch)');
                $statement->execute(['migration' => $migration, 'batch' => $batch]);
                $this->connection->commit();
            } catch (\Throwable $exception) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }
                throw $exception;
            }
            $executed[] = $migration;
        }

        return $executed;
    }
}
