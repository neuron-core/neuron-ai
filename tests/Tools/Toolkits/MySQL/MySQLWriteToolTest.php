<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class MySQLWriteToolTest extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
    }

    public function test_insert_reports_affected_rows_and_last_insert_id(): void
    {
        $result = (new MySQLWriteTool($this->pdo))(
            'INSERT INTO users (name) VALUES (:name)',
            [['name' => 'name', 'value' => 'Ada']]
        );

        $this->assertSame('Query executed successfully. 1 row(s) affected. Last insert ID: 1', $result);
        $this->assertSame(['Ada'], $this->names());
    }

    public function test_update_reports_affected_rows(): void
    {
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Grace'), ('Linus')");

        $result = (new MySQLWriteTool($this->pdo))(
            'UPDATE users SET name = UPPER(name) WHERE id <= :max',
            [['name' => ':max', 'value' => '2']]
        );

        $this->assertSame('Query executed successfully. 2 row(s) affected.', $result);
        $this->assertSame(['ADA', 'GRACE', 'Linus'], $this->names());
    }

    public function test_statement_matching_no_rows_reports_zero(): void
    {
        $this->assertSame(
            'Query executed successfully. 0 row(s) affected.',
            (new MySQLWriteTool($this->pdo))('DELETE FROM users WHERE id = 42')
        );
    }

    public function test_parameter_values_are_bound_as_data_not_sql(): void
    {
        $payload = "Robert'); DROP TABLE users; --";

        (new MySQLWriteTool($this->pdo))(
            'INSERT INTO users (name) VALUES (:name)',
            [['name' => 'name', 'value' => $payload]]
        );

        $this->assertSame([$payload], $this->names());
    }

    public function test_failed_execution_is_reported_when_errors_are_silent(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Ada')");

        $result = (new MySQLWriteTool($this->pdo))(
            'INSERT INTO users (name) VALUES (:name)',
            [['name' => 'name', 'value' => 'Ada']]
        );

        $this->assertSame('Error executing query: UNIQUE constraint failed: users.name', $result);
        $this->assertSame(['Ada'], $this->names());
    }

    public function test_insert_without_a_generated_key_reports_no_insert_id(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->expects($this->once())->method('lastInsertId')->willReturn('0');

        $this->assertSame(
            'Query executed successfully. 1 row(s) affected.',
            (new MySQLWriteTool($pdo))("INSERT INTO tags (label) VALUES ('php')")
        );
    }

    public function test_tool_schema(): void
    {
        $tool = new MySQLWriteTool($this->pdo);

        $this->assertSame('mysql_write_query', $tool->getName());
        $this->assertSame(['query'], $tool->getRequiredProperties());
    }

    /**
     * @return string[]
     */
    protected function names(): array
    {
        return $this->pdo->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}
