<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use PDO;

/**
 * PDO-backed store. One table:
 *
 * ```sql
 * -- ANSI (PostgreSQL, SQLite)
 * CREATE TABLE workflow_store (
 *     "partition" VARCHAR(255) NOT NULL,
 *     "key"       VARCHAR(255) NOT NULL,
 *     "value"     TEXT NOT NULL,
 *     updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY ("partition", "key")
 * );
 *
 * -- MySQL / MariaDB
 * CREATE TABLE workflow_store (
 *     `partition` VARCHAR(255) NOT NULL,
 *     `key`       VARCHAR(255) NOT NULL,
 *     `value`     TEXT NOT NULL,
 *     updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *     PRIMARY KEY (`partition`, `key`)
 * );
 * ```
 *
 * `partition` and `key` are reserved words in MySQL, hence the identifier
 * quoting — the dialect is fixed at construction, so the quoted column names
 * are computed once. `updated_at` is backend furniture — never read by the
 * contract.
 */
class DatabasePersistence implements PersistenceInterface
{
    protected bool $mysql;

    protected string $partitionCol;

    protected string $keyCol;

    protected string $valueCol;

    public function __construct(
        protected PDO $pdo,
        protected string $table = 'workflow_store',
    ) {
        $this->mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        $quote = $this->mysql ? '`' : '"';
        $this->partitionCol = $quote . 'partition' . $quote;
        $this->keyCol = $quote . 'key' . $quote;
        $this->valueCol = $quote . 'value' . $quote;
    }

    public function put(string $partition, string $key, string $value): void
    {
        // MySQL/MariaDB have no ON CONFLICT; PostgreSQL and SQLite have no ON DUPLICATE KEY.
        $upsert = $this->mysql
            ? "ON DUPLICATE KEY UPDATE {$this->valueCol} = VALUES({$this->valueCol}), updated_at = CURRENT_TIMESTAMP"
            : "ON CONFLICT ({$this->partitionCol}, {$this->keyCol}) DO UPDATE SET {$this->valueCol} = excluded.{$this->valueCol}, updated_at = CURRENT_TIMESTAMP";

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} ({$this->partitionCol}, {$this->keyCol}, {$this->valueCol}, updated_at)
            VALUES (:partition, :key, :value, CURRENT_TIMESTAMP)
            {$upsert}
        ");

        $stmt->execute(['partition' => $partition, 'key' => $key, 'value' => $value]);
    }

    public function get(string $partition, string $key): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT {$this->valueCol} FROM {$this->table} WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key",
        );
        $stmt->execute(['partition' => $partition, 'key' => $key]);
        $record = $stmt->fetch();

        if (!$record) {
            return null;
        }

        return (string) $record['value'];
    }

    public function delete(string $partition): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE {$this->partitionCol} = :partition");
        $stmt->execute(['partition' => $partition]);
    }
}
