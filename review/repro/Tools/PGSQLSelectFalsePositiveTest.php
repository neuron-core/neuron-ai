<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PGSQLSelectFalsePositiveTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function legitimateQueryProvider(): iterable
    {
        yield 'table containing eval' => ['SELECT id FROM evaluations'];
        yield 'table containing system' => ['SELECT id FROM system_logs'];
        yield 'column containing exec' => ['SELECT executed_at FROM jobs'];
        yield 'column containing pg_query' => ['SELECT pg_query_count FROM stats'];
    }

    #[DataProvider('legitimateQueryProvider')]
    public function test_identifiers_containing_function_names_are_accepted(string $query): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([['id' => 1]]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with($query)->willReturn($statement);

        $this->assertSame([['id' => 1]], (new PGSQLSelectTool($pdo))($query));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function functionCallProvider(): iterable
    {
        yield 'shell_exec call' => ["SELECT shell_exec('id')"];
        yield 'system call with spacing and mixed case' => ["SELECT SyStEm ('id')"];
        yield 'eval call' => ["SELECT eval('1')"];
    }

    #[DataProvider('functionCallProvider')]
    public function test_dangerous_function_calls_are_still_rejected(string $query): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertArrayHasKey('error', (new PGSQLSelectTool($pdo))($query));
    }
}
