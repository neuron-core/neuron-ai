<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use PDO;
use Throwable;

/**
 * PDO-backed store. One table:
 *
 * ```sql
 * -- ANSI (PostgreSQL, SQLite)
 * CREATE TABLE workflow_store (
 *     "partition" VARCHAR(255) NOT NULL,
 *     "key"       VARCHAR(255) NOT NULL,
 *     "value"     TEXT NOT NULL,
 *     created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY ("partition", "key")
 * );
 *
 * -- MySQL / MariaDB
 * CREATE TABLE workflow_store (
 *     `partition` VARCHAR(255) NOT NULL,
 *     `key`       VARCHAR(255) NOT NULL,
 *     `value`     TEXT NOT NULL,
 *     created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *     PRIMARY KEY (`partition`, `key`)
 * );
 * ```
 *
 * `partition` and `key` are reserved words in MySQL, hence the identifier
 * quoting — the dialect is fixed at construction, so the quoted column names
 * are computed once. The timestamp columns are backend furniture — never read
 * by the contract.
 */
class DatabasePersistence implements PersistenceInterface
{
    protected string $driver;

    protected bool $mysql;

    protected string $partitionCol;

    protected string $keyCol;

    protected string $valueCol;

    public function __construct(
        protected PDO $pdo,
        protected string $table = 'workflow_store',
    ) {
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->mysql = $this->driver === 'mysql';

        $quote = $this->mysql ? '`' : '"';
        $this->partitionCol = $quote . 'partition' . $quote;
        $this->keyCol = $quote . 'key' . $quote;
        $this->valueCol = $quote . 'value' . $quote;
    }

    protected function upsert(string $partition, string $key, string $value): void
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

    protected function deletePartition(string $partition): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE {$this->partitionCol} = :partition");
        $stmt->execute(['partition' => $partition]);
    }

    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        $records[$conditionKey] = $initialValue;

        return $this->commitIf($partition, $conditionKey, null, $records);
    }

    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        return $this->commitIf($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
    ): bool {
        return $this->commitIf($partition, $conditionKey, $expectedValue, deletePartition: true);
    }

    /** @param array<string, string> $writes */
    protected function commitIf(
        string $partition,
        string $conditionKey,
        ?string $expectedValue,
        array $writes = [],
        bool $deletePartition = false,
    ): bool {
        $this->pdo->beginTransaction();

        try {
            if ($expectedValue === null) {
                if (!isset($writes[$conditionKey])) {
                    $this->pdo->rollBack();
                    return false;
                }

                if (!$this->insertIfAbsent($partition, $conditionKey, $writes[$conditionKey])) {
                    $this->pdo->rollBack();
                    return false;
                }

                unset($writes[$conditionKey]);
            } else {
                $lock = $this->driver === 'sqlite' ? '' : ' FOR UPDATE';
                $stmt = $this->pdo->prepare(
                    "SELECT {$this->valueCol} FROM {$this->table} "
                    . "WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key{$lock}",
                );
                $stmt->execute(['partition' => $partition, 'key' => $conditionKey]);
                $current = $stmt->fetchColumn();

                if ($current === false || (string) $current !== $expectedValue) {
                    $this->pdo->rollBack();
                    return false;
                }
            }

            if ($deletePartition) {
                $this->deletePartition($partition);
            } else {
                foreach ($writes as $key => $value) {
                    $this->upsert($partition, $key, $value);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    protected function insertIfAbsent(string $partition, string $key, string $value): bool
    {
        $insert = $this->mysql
            ? "INSERT IGNORE INTO {$this->table} "
            : "INSERT INTO {$this->table} ";

        $insert .= "({$this->partitionCol}, {$this->keyCol}, {$this->valueCol}, updated_at) "
            . "VALUES (:partition, :key, :value, CURRENT_TIMESTAMP)";

        if (!$this->mysql) {
            $insert .= " ON CONFLICT ({$this->partitionCol}, {$this->keyCol}) DO NOTHING";
        }

        $stmt = $this->pdo->prepare($insert);
        $stmt->execute(['partition' => $partition, 'key' => $key, 'value' => $value]);

        return $stmt->rowCount() === 1;
    }
}
