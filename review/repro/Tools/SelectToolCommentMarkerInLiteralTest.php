<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SelectToolCommentMarkerInLiteralTest extends TestCase
{
    protected const MYSQL_REJECTION = "The query was rejected for security reasons.
            It looks like you are trying to run a write query using the read-only query tool.";

    public static function mysqlHiddenWrites(): array
    {
        return [
            'line comment marker in single-quoted literal' => ["SELECT '--' AS a; DELETE FROM users"],
            'block comment markers in literals' => ["SELECT '/*' AS a; DELETE FROM users; SELECT '*/'"],
            'line comment marker in double-quoted literal' => ['SELECT "--"; DELETE FROM users'],
            'escaped quote before comment marker' => ["SELECT 'a\\' -- '; DELETE FROM users"],
            'comment glued between keyword and table' => ['SELECT 1; DELETE/**/FROM users'],
            'double minus without trailing space is arithmetic' => ['SELECT 1--1; DELETE FROM users'],
            'executable version comment' => ['SELECT 1 /*!50000 ; DELETE FROM users */'],
        ];
    }

    #[DataProvider('mysqlHiddenWrites')]
    public function test_mysql_rejects_a_write_hidden_by_comment_stripping(string $query): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame(self::MYSQL_REJECTION, (new MySQLSelectTool($pdo))($query));
    }

    public static function pgsqlHiddenWrites(): array
    {
        return [
            'line comment marker in literal' => ["WITH a AS (SELECT '--'), d AS (DELETE FROM users RETURNING 1) SELECT * FROM d"],
            'line comment marker in dollar-quoted string' => ['WITH a AS (SELECT $$--$$), d AS (DELETE FROM users RETURNING 1) SELECT * FROM d'],
            'line comment marker in tagged dollar-quoted string' => ['WITH a AS (SELECT $q$--$q$), d AS (DELETE FROM users RETURNING 1) SELECT * FROM d'],
            'comment glued between keyword and table' => ['WITH d AS (DELETE/**/FROM users RETURNING 1) SELECT * FROM d'],
        ];
    }

    #[DataProvider('pgsqlHiddenWrites')]
    public function test_pgsql_rejects_a_write_hidden_by_comment_stripping(string $query): void
    {
        $postgres = PostgresSandbox::open();

        try {
            $postgres->pdo->exec("CREATE TABLE users (id serial PRIMARY KEY, name text); INSERT INTO users (name) VALUES ('Ada'), ('Grace')");

            $result = (new PGSQLSelectTool($postgres->pdo))($query);

            $this->assertArrayHasKey('error', $result);
            $this->assertSame(2, (int) $postgres->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        } finally {
            $postgres->drop();
        }
    }

    public function test_comment_markers_inside_literals_do_not_reject_a_legitimate_read(): void
    {
        $postgres = PostgresSandbox::open();

        try {
            $result = (new PGSQLSelectTool($postgres->pdo))("SELECT '--' AS dashes, \$\$/*\$\$ AS opener -- trailing comment");

            $this->assertSame([['dashes' => '--', 'opener' => '/*']], $result);
        } finally {
            $postgres->drop();
        }
    }
}
