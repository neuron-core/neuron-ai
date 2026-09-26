<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

use function str_contains;

class MySQLSchemaToolIndexFilterTest extends TestCase
{
    public function test_index_lookup_honours_the_table_allow_list(): void
    {
        $indexSql = null;
        $indexParams = null;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$indexSql, &$indexParams): PDOStatement {
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(function (?array $params = null) use ($sql, &$indexSql, &$indexParams): bool {
                if (str_contains($sql, 'INFORMATION_SCHEMA.STATISTICS')) {
                    $indexSql = $sql;
                    $indexParams = $params ?? [];
                }

                return true;
            });
            $statement->method('fetchAll')->willReturn([]);

            return $statement;
        });

        (new MySQLSchemaTool($pdo, ['orders']))();

        $this->assertSame(['orders'], $indexParams);
        $this->assertStringContainsString('TABLE_NAME IN (?)', (string) $indexSql);
    }

    public function test_indexes_of_tables_outside_the_allow_list_are_not_rendered(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $statement = $this->createMock(PDOStatement::class);
            $filtered = false;
            $statement->method('execute')->willReturnCallback(function (?array $params = null) use (&$filtered): bool {
                $filtered = $params !== null && $params !== [];

                return true;
            });
            $statement->method('fetchAll')->willReturnCallback(function () use ($sql, &$filtered): array {
                if (!str_contains($sql, 'INFORMATION_SCHEMA.STATISTICS')) {
                    return [];
                }
                // Simulate the database honouring the bound filter.
                $rows = [
                    ['TABLE_NAME' => 'orders', 'INDEX_NAME' => 'idx_orders_date', 'COLUMN_NAME' => 'created_at', 'SEQ_IN_INDEX' => 1, 'NON_UNIQUE' => 1, 'INDEX_TYPE' => 'BTREE', 'CARDINALITY' => 1],
                    ['TABLE_NAME' => 'secret_payroll', 'INDEX_NAME' => 'idx_salary', 'COLUMN_NAME' => 'salary', 'SEQ_IN_INDEX' => 1, 'NON_UNIQUE' => 1, 'INDEX_TYPE' => 'BTREE', 'CARDINALITY' => 1],
                ];

                return $filtered ? [$rows[0]] : $rows;
            });

            return $statement;
        });

        $output = (new MySQLSchemaTool($pdo, ['orders']))();

        $this->assertStringContainsString('idx_orders_date', $output);
        $this->assertStringNotContainsString('secret_payroll', $output);
    }
}
