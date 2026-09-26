<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class PGSQLSelectToolSideEffectsTest extends TestCase
{
    protected ?PostgresSandbox $postgres = null;

    protected function tearDown(): void
    {
        $this->postgres?->drop();
    }

    public function test_select_calling_a_writing_function_does_not_modify_rows(): void
    {
        $pdo = $this->seededPostgres();
        $pdo->exec('CREATE FUNCTION purge_users() RETURNS bigint LANGUAGE sql AS $$ WITH d AS (DELETE FROM users RETURNING 1) SELECT count(*) FROM d $$');

        try {
            (new PGSQLSelectTool($pdo))('SELECT purge_users()');
        } catch (PDOException) {
        }

        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_select_cannot_advance_a_sequence(): void
    {
        $pdo = $this->seededPostgres();

        try {
            (new PGSQLSelectTool($pdo))("SELECT setval('users_id_seq', 1000)");
        } catch (PDOException) {
        }

        $this->assertSame(2, (int) $pdo->query("SELECT last_value FROM users_id_seq")->fetchColumn());
    }

    public function test_select_cannot_change_session_configuration(): void
    {
        $pdo = $this->seededPostgres();

        (new PGSQLSelectTool($pdo))("SELECT set_config('search_path', 'pg_catalog', false)");

        $this->assertSame($this->postgres->schema, $pdo->query('SELECT current_schema()')->fetchColumn());
    }

    protected function seededPostgres(): PDO
    {
        $this->postgres = PostgresSandbox::open();
        $this->postgres->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->postgres->pdo->exec('CREATE TABLE users (id serial PRIMARY KEY, name text)');
        $this->postgres->pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Grace')");

        return $this->postgres->pdo;
    }
}
