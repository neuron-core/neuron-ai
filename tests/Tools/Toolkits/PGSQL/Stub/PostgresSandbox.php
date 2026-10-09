<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

use function getenv;
use function in_array;
use function uniqid;

/**
 * A connection to the integration Postgres whose search path is a private,
 * uniquely named schema: current_schema() sees only the tables a test creates.
 */
class PostgresSandbox
{
    public readonly string $schema;

    protected function __construct(public readonly PDO $pdo)
    {
        $this->schema = 'neuron_pgsql_toolkit_' . uniqid();
        $this->pdo->exec("CREATE SCHEMA {$this->schema}");
        $this->pdo->exec("SET search_path TO {$this->schema}");
    }

    public static function open(): self
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            TestCase::markTestSkipped("PDO driver 'pgsql' is unavailable.");
        }

        $dsn = getenv('WORKFLOW_PGSQL_DSN');
        if ($dsn === false || $dsn === '') {
            TestCase::markTestSkipped('Set WORKFLOW_PGSQL_DSN, WORKFLOW_PGSQL_USER and WORKFLOW_PGSQL_PASSWORD for PostgreSQL integration tests.');
        }

        try {
            $pdo = new PDO($dsn, getenv('WORKFLOW_PGSQL_USER') ?: null, getenv('WORKFLOW_PGSQL_PASSWORD') ?: null);
        } catch (PDOException $exception) {
            TestCase::markTestSkipped("No PostgreSQL server at {$dsn}: {$exception->getMessage()}");
        }

        return new self($pdo);
    }

    public function drop(): void
    {
        // DDL is transactional in Postgres: a transaction left open by a failed test would undo the drop
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo->exec("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
    }
}
