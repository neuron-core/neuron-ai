<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Closure;
use NeuronAI\Exceptions\PersistenceException;
use PDO;
use PDOException;
use Throwable;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function str_contains;
use function str_replace;
use function strlen;

/**
 * One table stores hex-encoded identifiers and base64-encoded values. Encoding
 * belongs to the backend: serializers and callers may supply arbitrary bytes.
 * Identifiers support up to 255 bytes before encoding.
 *
 * PostgreSQL / SQLite:
 * CREATE TABLE workflow_store (
 *     "partition" VARCHAR(510) NOT NULL,
 *     "key"       VARCHAR(510) NOT NULL,
 *     "value"     TEXT NOT NULL,
 *     updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY ("partition", "key")
 * );
 *
 * MySQL / MariaDB (requires strict SQL mode):
 * CREATE TABLE workflow_store (
 *     `partition` VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 *     `key`       VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 *     `value`     LONGTEXT CHARACTER SET ascii NOT NULL,
 *     updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (`partition`, `key`)
 * ) ENGINE=InnoDB;
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
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($this->mysql) {
            $mode = (string) $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            if (!str_contains($mode, 'STRICT_TRANS_TABLES') && !str_contains($mode, 'STRICT_ALL_TABLES')) {
                throw new PersistenceException('Workflow persistence requires MySQL strict SQL mode to prevent truncated records.');
            }
        }

        $quote = $this->mysql ? '`' : '"';
        $this->table = $quote . str_replace($quote, $quote . $quote, $this->table) . $quote;
        $this->partitionCol = $quote . 'partition' . $quote;
        $this->keyCol = $quote . 'key' . $quote;
        $this->valueCol = $quote . 'value' . $quote;
    }

    public function get(string $partition, string $key): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT {$this->valueCol} FROM {$this->table} WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key",
        );
        $stmt->execute(['partition' => $this->encodeKey($partition), 'key' => $this->encodeKey($key)]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        $decoded = base64_decode((string) $value, true);
        if ($decoded === false) {
            throw new PersistenceException("Invalid encoded Workflow record '{$key}' in partition '{$partition}'.");
        }

        return $decoded;
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

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
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
        $partition = $this->encodeKey($partition);
        $conditionKey = $this->encodeKey($conditionKey);
        $encoded = [];
        foreach ($writes as $key => $value) {
            $encoded[$this->encodeKey((string) $key)] = base64_encode($value);
        }

        return $this->transaction(function () use ($partition, $conditionKey, $expectedValue, $encoded, $deletePartition): bool {
            if ($expectedValue === null) {
                if (!$this->insertIfAbsent($partition, $conditionKey, $encoded[$conditionKey])) {
                    return false;
                }
                unset($encoded[$conditionKey]);
            } else {
                // SQLite must acquire its writer lock before reading the condition;
                // upgrading a deferred read transaction races with other writers.
                if ($this->driver === 'sqlite') {
                    $stmt = $this->pdo->prepare(
                        "UPDATE {$this->table} SET {$this->valueCol} = {$this->valueCol} "
                        . "WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key",
                    );
                    $stmt->execute(['partition' => $partition, 'key' => $conditionKey]);
                }
                $lock = $this->driver === 'sqlite' ? '' : ' FOR UPDATE';
                $stmt = $this->pdo->prepare(
                    "SELECT {$this->valueCol} FROM {$this->table} "
                    . "WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key{$lock}",
                );
                $stmt->execute(['partition' => $partition, 'key' => $conditionKey]);
                $current = $stmt->fetchColumn();
                if ($current === false || (string) $current !== base64_encode($expectedValue)) {
                    return false;
                }
            }

            if ($deletePartition) {
                $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE {$this->partitionCol} = :partition");
                $stmt->execute(['partition' => $partition]);
            } else {
                foreach ($encoded as $key => $value) {
                    $this->upsert($partition, (string) $key, $value);
                }
            }

            return true;
        });
    }

    protected function upsert(string $partition, string $key, string $value): void
    {
        $upsert = $this->mysql
            ? "ON DUPLICATE KEY UPDATE {$this->valueCol} = VALUES({$this->valueCol}), updated_at = CURRENT_TIMESTAMP"
            : "ON CONFLICT ({$this->partitionCol}, {$this->keyCol}) DO UPDATE SET {$this->valueCol} = excluded.{$this->valueCol}, updated_at = CURRENT_TIMESTAMP";
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} ({$this->partitionCol}, {$this->keyCol}, {$this->valueCol}, updated_at) "
            . "VALUES (:partition, :key, :value, CURRENT_TIMESTAMP) {$upsert}",
        );
        $stmt->execute(['partition' => $partition, 'key' => $key, 'value' => $value]);
    }

    protected function insertIfAbsent(string $partition, string $key, string $value): bool
    {
        $insert = "INSERT INTO {$this->table} ({$this->partitionCol}, {$this->keyCol}, {$this->valueCol}, updated_at) "
            . 'VALUES (:partition, :key, :value, CURRENT_TIMESTAMP)';
        if (!$this->mysql) {
            $insert .= " ON CONFLICT ({$this->partitionCol}, {$this->keyCol}) DO NOTHING";
        }

        try {
            $stmt = $this->pdo->prepare($insert);
            $stmt->execute(['partition' => $partition, 'key' => $key, 'value' => $value]);

            return $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            if ($this->mysql && ($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    protected function transaction(Closure $operation): bool
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    protected function encodeKey(string $key): string
    {
        if (strlen($key) > 255) {
            throw new PersistenceException('Workflow SQL partition names and record keys must not exceed 255 bytes.');
        }

        return bin2hex($key);
    }
}
