<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History\Stub;

use NeuronAI\Chat\History\SQLMessageStore;
use PDO;
use PHPUnit\Framework\TestCase;

use function getenv;
use function in_array;
use function uniqid;

/**
 * An SQLMessageStore over a uniquely named table of the integration PostgreSQL
 * database, so concurrent test runs never share rows. drop() removes the table.
 */
class PostgresMessageStore extends SQLMessageStore
{
    public static function open(): self
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            TestCase::markTestSkipped("PDO driver 'pgsql' is unavailable.");
        }

        $dsn = getenv('WORKFLOW_PGSQL_DSN');
        if ($dsn === false || $dsn === '') {
            TestCase::markTestSkipped('Set WORKFLOW_PGSQL_DSN, WORKFLOW_PGSQL_USER and WORKFLOW_PGSQL_PASSWORD for PostgreSQL integration tests.');
        }

        $pdo = new PDO($dsn, getenv('WORKFLOW_PGSQL_USER') ?: null, getenv('WORKFLOW_PGSQL_PASSWORD') ?: null);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $table = uniqid('neuron_chat_messages_');
        $pdo->exec("CREATE TABLE {$table} (
            id BIGSERIAL PRIMARY KEY,
            thread_id VARCHAR(255) NOT NULL,
            message_id VARCHAR(64) NOT NULL,
            role VARCHAR(32) NOT NULL,
            content TEXT NULL,
            meta TEXT NULL,
            archived_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (thread_id, message_id)
        )");

        return new self($pdo, $table);
    }

    public function drop(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
    }
}
