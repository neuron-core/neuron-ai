<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSchemaTool;
use PDO;
use PHPUnit\Framework\TestCase;

use function uniqid;

/**
 * Schema-per-tenant databases reuse table and constraint names across schemas:
 * the tool must describe only the tables of current_schema().
 */
class PGSQLSchemaToolSchemaIsolationTest extends TestCase
{
    protected PostgresSandbox $postgres;

    protected PDO $pdo;

    protected string $otherSchema;

    protected function setUp(): void
    {
        $this->postgres = PostgresSandbox::open();
        $this->pdo = $this->postgres->pdo;
        $this->otherSchema = 'neuron_pgsql_other_' . uniqid();

        $this->pdo->exec("
            CREATE SCHEMA {$this->otherSchema};
            CREATE TABLE {$this->otherSchema}.accounts (
                id int,
                code text,
                email text,
                CONSTRAINT accounts_pkey PRIMARY KEY (code),
                CONSTRAINT accounts_contact_key UNIQUE (email)
            );
            CREATE TABLE accounts (
                id int,
                code text,
                email text,
                CONSTRAINT accounts_pkey PRIMARY KEY (id),
                CONSTRAINT accounts_contact_key UNIQUE (code)
            );
        ");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DROP SCHEMA IF EXISTS {$this->otherSchema} CASCADE");
        $this->postgres->drop();
    }

    public function test_keys_of_same_named_constraints_in_other_schemas_are_ignored(): void
    {
        $output = (new PGSQLSchemaTool($this->pdo))();

        $this->assertStringContainsString("\n**Primary Key**: id\n**Unique Keys**: code\n", $output);
    }

    public function test_row_estimate_comes_from_the_current_schema_table(): void
    {
        $this->pdo->exec("INSERT INTO {$this->otherSchema}.accounts (code, email) SELECT g::text, g::text FROM generate_series(1, 7) g");
        $this->pdo->exec("INSERT INTO accounts (id, code) VALUES (1, 'a'), (2, 'b')");
        $this->pdo->query('SELECT pg_stat_force_next_flush()');
        $this->pdo->exec('SELECT 1');

        $output = (new PGSQLSchemaTool($this->pdo))();

        $this->assertStringContainsString("**Estimated Rows**: 2\n", $output);
    }
}
