<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PGSQLSelectKeywordWithoutWhitespaceTest extends TestCase
{
    public static function writesWithoutWhitespaceAfterTheKeyword(): array
    {
        return [
            'update in a CTE' => ['WITH x AS (UPDATE"users"SET"name"=\'pwned\' RETURNING 1) SELECT * FROM x'],
            'update separated by comments' => ['WITH x AS (UPDATE/**/users/**/SET/**/name=\'pwned\' RETURNING 1) SELECT * FROM x'],
        ];
    }

    #[DataProvider('writesWithoutWhitespaceAfterTheKeyword')]
    public function test_write_keyword_followed_by_a_quoted_identifier_is_rejected(string $query): void
    {
        $postgres = PostgresSandbox::open();

        try {
            $postgres->pdo->exec("CREATE TABLE users (id serial PRIMARY KEY, name text); INSERT INTO users (name) VALUES ('Ada')");

            $result = (new PGSQLSelectTool($postgres->pdo))($query);

            $this->assertArrayHasKey('error', $result);
            $this->assertSame([['name' => 'Ada']], $postgres->pdo->query('SELECT name FROM users')->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $postgres->drop();
        }
    }
}
