<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use PDO;
use ProjectSync\Exceptions\MigrationException;
use RuntimeException;

final readonly class MigrationRunner
{
    public function __construct(private PDO $connection, private string $migrationPath)
    {
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->connection->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(255) NOT NULL PRIMARY KEY, batch INT UNSIGNED NOT NULL, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $appliedStatement = $this->connection->prepare('SELECT migration FROM schema_migrations ORDER BY migration ASC');
        $appliedStatement->execute();
        /** @var list<string> $applied */
        $applied = $appliedStatement->fetchAll(PDO::FETCH_COLUMN);

        return $applied;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $applied = $this->applied();
        $files = glob($this->migrationPath . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $pending = [];
        foreach ($files as $file) {
            $migration = basename($file, '.php');
            if (!in_array($migration, $applied, true)) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /** @return list<string> */
    public function run(): array
    {
        $applied = $this->applied();
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

            $usesTransactions = $this->usesTransactionalDdl();
            if ($usesTransactions) {
                $this->connection->beginTransaction();
            }
            try {
                $callback($this->connection);
                $statement = $this->connection->prepare('INSERT INTO schema_migrations (migration, batch) VALUES (:migration, :batch)');
                $statement->execute(['migration' => $migration, 'batch' => $batch]);
                if ($usesTransactions) {
                    $this->connection->commit();
                }
            } catch (\Throwable $exception) {
                if ($usesTransactions && $this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }
                throw MigrationException::failed($migration, $exception);
            }
            $executed[] = $migration;
        }

        return $executed;
    }

    private function usesTransactionalDdl(): bool
    {
        return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql';
    }
}
