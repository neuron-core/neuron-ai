<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tests\Support\PhpWarningsAsExceptions;
use NeuronAI\Tests\Tools\Toolkits\MySQL\Stub\MySQLSandbox;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MySQLWriteToolTest extends TestCase
{
    use PhpWarningsAsExceptions;

    protected PDO $pdo;

    protected ?MySQLSandbox $sandbox = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
    }

    protected function tearDown(): void
    {
        $this->sandbox?->drop();
    }

    public function test_insert_reports_affected_rows_and_generated_id(): void
    {
        $result = (new MySQLWriteTool($this->pdo))(
            'INSERT INTO users (name) VALUES (:name)',
            [['name' => 'name', 'value' => 'Ada']]
        );

        $this->assertSame('Query executed successfully. 1 row(s) affected. First generated ID: 1.', $result);
        $this->assertSame(['Ada'], $this->names());
    }


    public function test_statement_matching_no_rows_reports_zero(): void
    {
        $this->assertSame(
            'Query executed successfully. 0 row(s) affected.',
            (new MySQLWriteTool($this->pdo))('DELETE FROM users WHERE id = 42')
        );
    }

    public function test_null_parameters_bind_nothing(): void
    {
        $this->assertSame(
            'Query executed successfully. 0 row(s) affected.',
            $this->withWarningsAsExceptions(fn (): string => (new MySQLWriteTool($this->pdo))('DELETE FROM users WHERE id = 42', null))
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function databaseErrorProvider(): iterable
    {
        yield 'refused when prepared' => ["INSRT INTO users (name) VALUES ('Grace')", 'SQLSTATE[HY000]: General error: 1 near "INSRT": syntax error'];
        yield 'refused when executed' => ["INSERT INTO users (name) VALUES ('Ada')", 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.name'];
    }

    #[DataProvider('databaseErrorProvider')]
    public function test_database_errors_are_returned_to_the_model(string $query, string $error): void
    {
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Ada')");

        $this->assertError($error, (new MySQLWriteTool($this->pdo))($query));
        $this->assertSame(['Ada'], $this->names());
    }

    public function test_failed_execution_is_reported_when_errors_are_silent(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Ada')");

        $result = (new MySQLWriteTool($this->pdo))(
            'INSERT INTO users (name) VALUES (:name)',
            [['name' => 'name', 'value' => 'Ada']]
        );

        $this->assertError('Error executing query: UNIQUE constraint failed: users.name', $result);
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

    public static function serverProvider(): iterable
    {
        yield 'mysql' => [3306];
        yield 'mariadb' => [3307];
    }

    #[DataProvider('serverProvider')]
    public function test_update_reports_affected_rows_without_an_id(int $port): void
    {
        $pdo = $this->server($port);
        $pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Grace'), ('Linus')");

        $result = (new MySQLWriteTool($pdo))(
            'UPDATE users SET name = UPPER(name) WHERE id <= :max',
            [['name' => ':max', 'value' => '2']]
        );

        $this->assertSame('Query executed successfully. 2 row(s) affected.', $result);
        $this->assertSame(['ADA', 'GRACE', 'Linus'], $pdo->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    #[DataProvider('serverProvider')]
    public function test_an_insert_in_any_spelling_reports_its_generated_id(int $port): void
    {
        $tool = new MySQLWriteTool($this->server($port));

        $this->assertSame(
            'Query executed successfully. 1 row(s) affected. First generated ID: 1.',
            $tool("/* seed */ insert into users (name) values ('Ada')")
        );
    }

    #[DataProvider('serverProvider')]
    public function test_a_multi_row_insert_reports_the_first_generated_id(int $port): void
    {
        $tool = new MySQLWriteTool($this->server($port));

        $this->assertSame(
            'Query executed successfully. 2 row(s) affected. First generated ID: 1.',
            $tool("INSERT INTO users (name) VALUES ('Ada'), ('Grace')")
        );
    }

    #[DataProvider('serverProvider')]
    public function test_statements_generating_no_id_report_none_after_an_insert(int $port): void
    {
        $pdo = $this->server($port);
        $pdo->exec("INSERT INTO users (name) VALUES ('Ada')");
        $pdo->exec('CREATE TABLE tags (label VARCHAR(20))');
        $tool = new MySQLWriteTool($pdo);

        $this->assertSame('Query executed successfully. 0 row(s) affected.', $tool("INSERT IGNORE INTO users (name) VALUES ('Ada')"));
        $this->assertSame('Query executed successfully. 0 row(s) affected.', $tool('INSERT INTO users (name) SELECT name FROM users WHERE 0'));
        $this->assertSame('Query executed successfully. 1 row(s) affected.', $tool("INSERT INTO tags (label) VALUES ('php')"));
    }

    public function test_returning_rows_are_reported_on_mariadb(): void
    {
        $tool = new MySQLWriteTool($this->server(3307));

        $this->assertSame(
            'Query executed successfully. 1 row(s) affected. Returned rows: [{"id":1,"name":"Ada"}]',
            $tool("INSERT INTO users (name) VALUES ('Ada') RETURNING id, name")
        );
    }

    public function test_tool_schema(): void
    {
        $tool = new MySQLWriteTool($this->pdo);

        $this->assertSame('mysql_write_query', $tool->getName());
        $this->assertSame(['query'], $tool->getRequiredProperties());
    }

    protected function assertError(string $message, mixed $result): void
    {
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame($message, $result->getText());
    }

    /**
     * @return string[]
     */
    protected function names(): array
    {
        return $this->pdo->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function server(int $port): PDO
    {
        $this->sandbox = MySQLSandbox::open($port);
        $this->sandbox->pdo->exec('CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) UNIQUE)');

        return $this->sandbox->pdo;
    }
}
