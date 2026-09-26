<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PHPUnit\Framework\TestCase;

class SelectToolCommentAsWhitespaceTest extends TestCase
{
    protected const REJECTION = "The query was rejected for security reasons.
            It looks like you are trying to run a write query using the read-only query tool.";

    public function test_mysql_rejects_a_delete_whose_keywords_are_separated_by_a_comment(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame(self::REJECTION, (new MySQLSelectTool($pdo))('SELECT 1; DELETE/**/FROM users'));
    }

    public function test_pgsql_rejects_a_delete_whose_keywords_are_separated_by_a_comment(): void
    {
        $postgres = PostgresSandbox::open();

        try {
            $postgres->pdo->exec("CREATE TABLE users (id serial PRIMARY KEY, name text); INSERT INTO users (name) VALUES ('Ada'), ('Grace')");

            $result = (new PGSQLSelectTool($postgres->pdo))('WITH d AS (DELETE/**/FROM users RETURNING *) SELECT * FROM d');

            $this->assertArrayHasKey('error', $result);
            $this->assertSame(2, (int) $postgres->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        } finally {
            $postgres->drop();
        }
    }
}
