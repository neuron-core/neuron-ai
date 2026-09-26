<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Support\PhpWarningsAsExceptions;
use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PGSQLSelectToolTest extends TestCase
{
    use PhpWarningsAsExceptions;

    protected const REJECTION = [
        'error' => "The query was rejected for security reasons.
It looks like you are trying to run a write query using the read-only query tool.",
    ];

    protected ?PostgresSandbox $postgres = null;

    protected function tearDown(): void
    {
        $this->postgres?->drop();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readQueryProvider(): iterable
    {
        yield 'select' => ['SELECT id, name FROM users WHERE id = :id'];
        yield 'lowercase select' => ['select * from users'];
        yield 'leading whitespace and newlines' => ["\n\t  SELECT 1"];
        yield 'common table expression' => ['WITH cte AS (SELECT 1) SELECT * FROM cte'];
        yield 'explain' => ['EXPLAIN SELECT * FROM users'];
        yield 'show' => ['SHOW search_path'];
        yield 'leading block comment' => ['/* report */ SELECT 1'];
        yield 'leading line comment' => ["-- report\nSELECT 1"];
        yield 'keywords as column name prefixes' => ['SELECT created_at, updated_at, deleted_at FROM users'];
        yield 'keywords as column name suffixes' => ['SELECT last_update, soft_delete, pre_insert FROM users'];
        yield 'trailing semicolon' => ['SELECT 1;'];
        yield 'chained read statements' => ['SELECT 1; SELECT 2'];
        yield 'json operators' => ["SELECT meta->>'k' FROM users WHERE meta ? 'k'"];
    }

    #[DataProvider('readQueryProvider')]
    public function test_read_query_is_prepared_verbatim_and_its_rows_returned(string $query): void
    {
        $rows = [['id' => 1, 'name' => 'Ada']];
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($rows);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with($query)->willReturn($statement);

        $this->assertSame($rows, (new PGSQLSelectTool($pdo))($query));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedQueryProvider(): iterable
    {
        yield 'insert' => ['INSERT INTO users (name) VALUES (1)'];
        yield 'update' => ['UPDATE users SET name = 1'];
        yield 'delete' => ['DELETE FROM users'];
        yield 'drop' => ['DROP TABLE users'];
        yield 'create' => ['CREATE TABLE t (id int)'];
        yield 'alter' => ['ALTER TABLE users ADD c int'];
        yield 'truncate' => ['TRUNCATE users'];
        yield 'grant' => ['GRANT ALL ON users TO public'];
        yield 'copy to file' => ["COPY users TO '/tmp/users.csv'"];
        yield 'copy from program' => ["COPY users FROM PROGRAM 'id'"];
        yield 'anonymous code block' => ['DO $$ BEGIN DELETE FROM users; END $$'];
        yield 'set role' => ['SET ROLE postgres'];
        yield 'call' => ['CALL cleanup()'];
        yield 'lock' => ['LOCK TABLE users'];
        yield 'vacuum' => ['VACUUM users'];
        yield 'lowercase write' => ['delete from users'];
        yield 'write after whitespace' => ["\n\t DROP TABLE users"];
        yield 'write hidden after a block comment' => ['/* SELECT */ DELETE FROM users'];
        yield 'write hidden after a line comment' => ["-- SELECT\nDELETE FROM users"];
        yield 'write statement after a select' => ['SELECT 1; DROP TABLE users'];
        yield 'write statement after a commented semicolon' => ['SELECT 1 /* ; */; DELETE FROM users'];
        yield 'copy statement after a select' => ["SELECT 1; COPY users TO '/tmp/users.csv'"];
        yield 'set after a select' => ['SELECT 1; SET ROLE postgres'];
        yield 'delete in a common table expression' => ['WITH d AS (DELETE FROM users RETURNING *) SELECT * FROM d'];
        yield 'insert in a common table expression' => ['WITH i AS (INSERT INTO users (name) VALUES (1) RETURNING id) SELECT * FROM i'];
        yield 'update in a common table expression' => ['WITH u AS (UPDATE users SET name = 1 RETURNING id) SELECT * FROM u'];
        yield 'update glued to a quoted identifier before a spaced set clause' => ['WITH u AS (UPDATE"users" SET name = 1 RETURNING id) SELECT * FROM u'];
        yield 'write inside explain analyze' => ['EXPLAIN ANALYZE DELETE FROM users'];
        yield 'shell function call' => ["SELECT shell_exec('id')"];
        yield 'into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/x'"];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \n "];
        yield 'comment only' => ['/* SELECT 1 */'];
    }

    #[DataProvider('rejectedQueryProvider')]
    public function test_rejected_query_never_reaches_the_database(string $query): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame(self::REJECTION, (new PGSQLSelectTool($pdo))($query));
    }

    public function test_parameters_are_bound_with_or_without_the_colon_prefix(): void
    {
        $rows = (new PGSQLSelectTool($this->seededSqlite()))(
            'SELECT name FROM users WHERE id = :id OR name = :name ORDER BY id',
            [['name' => 'id', 'value' => '1'], ['name' => ':name', 'value' => 'Grace']]
        );

        $this->assertSame([['name' => 'Ada'], ['name' => 'Grace']], $rows);
    }

    public function test_null_parameters_bind_nothing(): void
    {
        $tool = new PGSQLSelectTool($this->seededSqlite());

        $this->assertSame(
            [['total' => 2]],
            $this->withWarningsAsExceptions(fn (): array => $tool('SELECT COUNT(*) AS total FROM users', null))
        );
    }

    public function test_data_modifying_cte_leaves_postgres_rows_untouched(): void
    {
        $pdo = $this->seededPostgres();

        $result = (new PGSQLSelectTool($pdo))('WITH d AS (DELETE FROM users RETURNING *) SELECT * FROM d');

        $this->assertSame(self::REJECTION, $result);
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_parameter_values_are_bound_as_data_on_postgres(): void
    {
        $pdo = $this->seededPostgres();
        $payload = "x' OR '1'='1'; DELETE FROM users; --";

        $tool = new PGSQLSelectTool($pdo);

        $this->assertSame([], $tool('SELECT name FROM users WHERE name = :name', [['name' => 'name', 'value' => $payload]]));
        $this->assertSame(
            [['name' => 'Grace']],
            $tool('SELECT name FROM users WHERE name ILIKE :pattern', [['name' => 'pattern', 'value' => 'gr%']])
        );
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_tool_schema(): void
    {
        $tool = new PGSQLSelectTool($this->createMock(PDO::class));

        $this->assertSame('pgsql_select_query', $tool->getName());
        $this->assertSame(['query'], $tool->getRequiredProperties());
    }

    protected function seededSqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Ada'), (2, 'Grace')");

        return $pdo;
    }

    protected function seededPostgres(): PDO
    {
        $this->postgres = PostgresSandbox::open();
        $this->postgres->pdo->exec('CREATE TABLE users (id serial PRIMARY KEY, name text)');
        $this->postgres->pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Grace')");

        return $this->postgres->pdo;
    }
}
