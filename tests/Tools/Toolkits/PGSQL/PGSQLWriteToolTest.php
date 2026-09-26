<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Support\PhpWarningsAsExceptions;
use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class PGSQLWriteToolTest extends TestCase
{
    use PhpWarningsAsExceptions;

    protected ?PostgresSandbox $postgres = null;

    protected function tearDown(): void
    {
        $this->postgres?->drop();
    }

    public function test_update_reports_affected_rows(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Grace'), ('Linus')");

        $result = (new PGSQLWriteTool($pdo))(
            'UPDATE users SET name = UPPER(name) WHERE id <= :max',
            [['name' => ':max', 'value' => '2']]
        );

        $this->assertSame('Query executed successfully. 2 row(s) affected.', $result);
        $this->assertSame(['ADA', 'GRACE', 'Linus'], $this->names($pdo));
    }

    public function test_null_parameters_bind_nothing(): void
    {
        $tool = new PGSQLWriteTool($this->sqlite());

        $this->assertSame(
            'Query executed successfully. 0 row(s) affected.',
            $this->withWarningsAsExceptions(fn (): string => $tool('DELETE FROM users WHERE id = 42', null))
        );
    }

    public function test_failed_execution_is_reported_when_errors_are_silent(): void
    {
        $pdo = $this->sqlite();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $pdo->exec("INSERT INTO users (name) VALUES ('Ada')");

        $result = (new PGSQLWriteTool($pdo))(
            'INSERT INTO users (name) VALUES (:name)',
            [['name' => 'name', 'value' => 'Ada']]
        );

        $this->assertSame('Error executing query: UNIQUE constraint failed: users.name', $result);
    }

    public function test_insert_into_a_serial_table_reports_the_new_id_on_postgres(): void
    {
        $pdo = $this->postgres();

        $result = (new PGSQLWriteTool($pdo))(
            'INSERT INTO users (name) VALUES (:name)',
            [['name' => 'name', 'value' => 'Ada']]
        );

        $this->assertSame('Query executed successfully. 1 row(s) affected. Last insert ID: 1', $result);
        $this->assertSame(['Ada'], $this->names($pdo));
    }

    public function test_parameter_values_are_bound_as_data_on_postgres(): void
    {
        $pdo = $this->postgres();
        $payload = "Robert'); DROP TABLE users; --";

        (new PGSQLWriteTool($pdo))('INSERT INTO users (name) VALUES (:name)', [['name' => 'name', 'value' => $payload]]);

        $this->assertSame([$payload], $this->names($pdo));
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
            (new PGSQLWriteTool($pdo))("INSERT INTO tags (label) VALUES ('php')")
        );
    }

    public function test_tool_schema(): void
    {
        $tool = new PGSQLWriteTool($this->createMock(PDO::class));

        $this->assertSame('pgsql_write_query', $tool->getName());
        $this->assertSame(['query'], $tool->getRequiredProperties());
    }

    protected function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');

        return $pdo;
    }

    protected function postgres(): PDO
    {
        $this->postgres = PostgresSandbox::open();
        $this->postgres->pdo->exec('CREATE TABLE users (id serial PRIMARY KEY, name text UNIQUE)');

        return $this->postgres->pdo;
    }

    /**
     * @return string[]
     */
    protected function names(PDO $pdo): array
    {
        return $pdo->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}
