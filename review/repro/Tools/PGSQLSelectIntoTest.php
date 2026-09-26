<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PGSQLSelectIntoTest extends TestCase
{
    public static function selectIntoQueries(): array
    {
        return [
            'plain' => ['SELECT * INTO stolen FROM users'],
            'table keyword' => ['SELECT * INTO TABLE stolen FROM users'],
            'unlogged' => ['SELECT name INTO UNLOGGED stolen FROM users'],
            'inside CTE' => ['WITH u AS (SELECT * FROM users) SELECT * INTO stolen FROM u'],
        ];
    }

    #[DataProvider('selectIntoQueries')]
    public function test_select_into_cannot_create_a_table(string $query): void
    {
        $postgres = PostgresSandbox::open();

        try {
            $postgres->pdo->exec("CREATE TABLE users (id serial PRIMARY KEY, name text); INSERT INTO users (name) VALUES ('Ada')");

            $result = (new PGSQLSelectTool($postgres->pdo))($query);

            $this->assertArrayHasKey('error', $result);
            $this->assertNull($postgres->pdo->query("SELECT to_regclass('stolen')")->fetchColumn());
        } finally {
            $postgres->drop();
        }
    }
}
