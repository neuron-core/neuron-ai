<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Closure;
use NeuronAI\Exceptions\PersistenceException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use function array_fill;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function count;
use function implode;
use function str_contains;
use function str_replace;
use function strlen;

/**
 * One table stores hex-encoded identifiers and base64-encoded values. Encoding
 * belongs to the backend: serializers and callers may supply arbitrary bytes.
 * Identifiers support up to 255 bytes before encoding.
 *
 * The PDO must use PDO::ERRMODE_EXCEPTION, the default since PHP 8. Inside a
 * transaction the application opened on it, each operation runs in a savepoint
 * and commits or rolls back with the enclosing transaction.
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
    protected const SAVEPOINT = 'neuron_workflow';

    protected string $driver;
    protected bool $mysql;
    protected bool $strictModeVerified = false;
    protected string $partitionCol;
    protected string $keyCol;
    protected string $valueCol;

    /** @var array<string, PDOStatement> */
    protected array $statements = [];

    /**
     * @throws PersistenceException
     */
    public function __construct(
        protected PDO $pdo,
        protected string $table = 'workflow_store',
    ) {
        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new PersistenceException('Workflow persistence requires the PDO error mode PDO::ERRMODE_EXCEPTION, so that no failed write passes unnoticed.');
        }

        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->mysql = $this->driver === 'mysql';

        $quote = $this->mysql ? '`' : '"';
        $this->table = $quote . str_replace($quote, $quote . $quote, $this->table) . $quote;
        $this->partitionCol = $quote . 'partition' . $quote;
        $this->keyCol = $quote . 'key' . $quote;
        $this->valueCol = $quote . 'value' . $quote;
    }

    public function get(string $partition, string $key): ?string
    {
        $stmt = $this->execute(
            "SELECT {$this->valueCol} FROM {$this->table} WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key",
            ['partition' => $this->encodeKey($partition), 'key' => $this->encodeKey($key)],
        );
        $value = $stmt->fetchColumn();
        $stmt->closeCursor();
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
        $this->verifyStrictMode();
        $partition = $this->encodeKey($partition);
        $initialValue = null;
        if ($expectedValue === null) {
            $initialValue = base64_encode($writes[$conditionKey]);
            unset($writes[$conditionKey]);
        }
        $conditionKey = $this->encodeKey($conditionKey);
        // Hex-encoded keys can be all digits and PHP would coerce them to int array
        // indexes, so the encoded records are kept as a list of pairs.
        $encoded = [];
        foreach ($writes as $key => $value) {
            $encoded[] = [$this->encodeKey((string) $key), base64_encode($value)];
        }

        return $this->atomically(function () use ($partition, $conditionKey, $expectedValue, $initialValue, $encoded, $deletePartition): bool {
            if ($initialValue !== null) {
                if (!$this->insertIfAbsent($partition, $conditionKey, $initialValue)) {
                    return false;
                }
            } else {
                // SQLite must acquire its writer lock before reading the condition;
                // upgrading a deferred read transaction races with other writers.
                if ($this->driver === 'sqlite') {
                    $this->execute(
                        "UPDATE {$this->table} SET {$this->valueCol} = {$this->valueCol} "
                        . "WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key",
                        ['partition' => $partition, 'key' => $conditionKey],
                    );
                }
                $lock = $this->driver === 'sqlite' ? '' : ' FOR UPDATE';
                $stmt = $this->execute(
                    "SELECT {$this->valueCol} FROM {$this->table} "
                    . "WHERE {$this->partitionCol} = :partition AND {$this->keyCol} = :key{$lock}",
                    ['partition' => $partition, 'key' => $conditionKey],
                );
                $current = $stmt->fetchColumn();
                $stmt->closeCursor();
                if ($current === false || (string) $current !== base64_encode($expectedValue)) {
                    return false;
                }
            }

            if ($deletePartition) {
                $this->execute(
                    "DELETE FROM {$this->table} WHERE {$this->partitionCol} = :partition",
                    ['partition' => $partition],
                );
            } elseif ($encoded !== []) {
                $this->upsert($partition, $encoded);
            }

            return true;
        });
    }

    /**
     * The records of one write travel in one statement.
     *
     * @param non-empty-list<array{string, string}> $records Encoded keys with their encoded values.
     */
    protected function upsert(string $partition, array $records): void
    {
        $upsert = $this->mysql
            ? "ON DUPLICATE KEY UPDATE {$this->valueCol} = VALUES({$this->valueCol}), updated_at = CURRENT_TIMESTAMP"
            : "ON CONFLICT ({$this->partitionCol}, {$this->keyCol}) DO UPDATE SET {$this->valueCol} = excluded.{$this->valueCol}, updated_at = CURRENT_TIMESTAMP";
        $rows = implode(', ', array_fill(0, count($records), '(?, ?, ?, CURRENT_TIMESTAMP)'));
        $parameters = [];
        foreach ($records as [$key, $value]) {
            $parameters = [...$parameters, $partition, $key, $value];
        }

        $this->execute(
            "INSERT INTO {$this->table} ({$this->partitionCol}, {$this->keyCol}, {$this->valueCol}, updated_at) "
            . "VALUES {$rows} {$upsert}",
            $parameters,
        );
    }

    protected function insertIfAbsent(string $partition, string $key, string $value): bool
    {
        $insert = "INSERT INTO {$this->table} ({$this->partitionCol}, {$this->keyCol}, {$this->valueCol}, updated_at) "
            . 'VALUES (:partition, :key, :value, CURRENT_TIMESTAMP)';
        if (!$this->mysql) {
            $insert .= " ON CONFLICT ({$this->partitionCol}, {$this->keyCol}) DO NOTHING";
        }

        try {
            return $this->execute($insert, ['partition' => $partition, 'key' => $key, 'value' => $value])->rowCount() === 1;
        } catch (PDOException $e) {
            if ($this->mysql && ($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * The supplied PDO keeps one session, so a single check covers every write.
     *
     * @throws PersistenceException
     */
    protected function verifyStrictMode(): void
    {
        if (!$this->mysql || $this->strictModeVerified) {
            return;
        }

        $mode = (string) $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        if (!str_contains($mode, 'STRICT_TRANS_TABLES') && !str_contains($mode, 'STRICT_ALL_TABLES')) {
            throw new PersistenceException('Workflow persistence requires MySQL strict SQL mode to prevent truncated records.');
        }

        $this->strictModeVerified = true;
    }

    /**
     * A run repeats the same few queries at every step, so each is prepared once
     * and kept. One that failed is prepared again at its next use: the SQLite
     * driver cannot run a statement whose first execution failed. A caller that
     * fetches closes the cursor, or the kept statement would hold its read open
     * and, on SQLite, lock other connections out of writing.
     *
     * @param array<array-key, string> $parameters
     */
    protected function execute(string $query, array $parameters): PDOStatement
    {
        $statement = $this->statements[$query] ??= $this->pdo->prepare($query);

        try {
            $statement->execute($parameters);
        } catch (Throwable $e) {
            unset($this->statements[$query]);
            throw $e;
        }

        return $statement;
    }

    protected function atomically(Closure $operation): bool
    {
        return $this->pdo->inTransaction() ? $this->savepoint($operation) : $this->transaction($operation);
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

    /**
     * The application's transaction owns the commit; the savepoint lets a failed
     * operation undo only its own writes.
     */
    protected function savepoint(Closure $operation): bool
    {
        $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        try {
            $result = $operation();
            $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);

            return $result;
        } catch (Throwable $e) {
            $this->rollBackToSavepoint();
            throw $e;
        }
    }

    protected function rollBackToSavepoint(): void
    {
        try {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } catch (PDOException) {
            // A MySQL deadlock already rolled back the enclosing transaction, savepoint
            // included: the operation's own failure is the one to report.
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
