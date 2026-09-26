<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MySQLSelectMultiStatementTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function chainedWriteProvider(): iterable
    {
        yield 'grant' => ["SELECT 1; GRANT ALL ON *.* TO 'attacker'@'%'"];
        yield 'set global' => ['SELECT 1; SET GLOBAL general_log = 1'];
        yield 'lock tables' => ['SELECT 1; LOCK TABLES users WRITE'];
        yield 'rename table' => ['SELECT 1; RENAME TABLE users TO users_old'];
        yield 'separator hidden from the sanitizer by a non-comment double dash' => ['SELECT 1--1; DROP TABLE users'];
        yield 'separator hidden from the sanitizer inside a literal' => ["SELECT '--'; DROP TABLE users"];
    }

    public function test_single_trailing_semicolon_is_allowed(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn([['one' => 1]]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('SELECT 1 AS one; ')->willReturn($statement);

        $this->assertSame([['one' => 1]], (new MySQLSelectTool($pdo))('SELECT 1 AS one; '));
    }

    #[DataProvider('chainedWriteProvider')]
    public function test_statement_chained_after_a_select_is_rejected(string $query): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame(
            "The query was rejected for security reasons.
            It looks like you are trying to run a write query using the read-only query tool.",
            (new MySQLSelectTool($pdo))($query)
        );
    }
}
