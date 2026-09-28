<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\PhpWarningsAsExceptions;
use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PGSQLSelectToolTest extends TestCase
{
    use PhpWarningsAsExceptions;

    protected const STATEMENT_TYPE = 'Start the query with one of these keywords, with nothing before it: SELECT, WITH, EXPLAIN, SHOW.';

    protected const ONE_STATEMENT = "Send one statement per call. Pass values that contain ';' as parameters.";

    protected const DATABASE_REFUSAL = 'This tool is read-only. The database refused the query: ';

    /**
     * @var string[]
     */
    protected array $calls = [];

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
        yield 'trailing semicolon' => ['SELECT 1;'];
        yield 'keyword glued to a quoted identifier' => ['SELECT"name" FROM users'];
        yield 'keywords as column name prefixes' => ['SELECT created_at, updated_at, deleted_at FROM users'];
        yield 'keywords as column name suffixes' => ['SELECT last_update, soft_delete, pre_insert FROM users'];
        yield 'json operators' => ["SELECT meta->>'k' FROM users WHERE meta ? 'k'"];
        yield 'comment markers inside literals' => ["SELECT '--' AS dashes, \$\$/*\$\$ AS opener -- trailing comment"];
        yield 'TOOLS-54: table containing eval' => ['SELECT id FROM evaluations'];
        yield 'TOOLS-54: table containing system' => ['SELECT id FROM system_logs'];
        yield 'TOOLS-54: column containing exec' => ['SELECT executed_at FROM jobs'];
        yield 'TOOLS-54: literal containing exec' => ["SELECT id FROM jobs WHERE status = 'executed'"];
    }

    #[DataProvider('readQueryProvider')]
    public function test_read_query_runs_verbatim_in_a_read_only_transaction_that_is_rolled_back(string $query): void
    {
        $rows = [['id' => 1, 'name' => 'Ada']];

        $result = (new PGSQLSelectTool($this->connection($query, $this->statementReturning($rows))))($query);

        $this->assertSame($rows, $result);
        $this->assertSame(['exec: START TRANSACTION READ ONLY', 'prepare', 'rollBack'], $this->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherStatementTypeProvider(): iterable
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
        yield 'copy to program' => ["COPY (SELECT 1) TO PROGRAM 'id'"];
        yield 'anonymous code block' => ['DO $$ BEGIN DELETE FROM users; END $$'];
        yield 'set role' => ['SET ROLE postgres'];
        yield 'call' => ['CALL cleanup()'];
        yield 'lock' => ['LOCK TABLE users'];
        yield 'vacuum' => ['VACUUM users'];
        yield 'describe is not a postgres statement' => ['DESCRIBE users'];
        yield 'lowercase write' => ['delete from users'];
        yield 'write after whitespace' => ["\n\t DROP TABLE users"];
        yield 'leading block comment' => ['/* report */ SELECT 1'];
        yield 'leading line comment' => ["-- report\nSELECT 1"];
        yield 'write hidden after a block comment' => ['/* SELECT */ DELETE FROM users'];
        yield 'write hidden after a line comment' => ["-- SELECT\nDELETE FROM users"];
        yield 'copy to program hidden by a nested comment' => ["/* /* */ SELECT */ COPY (SELECT 1) TO PROGRAM 'touch /tmp/pwned'"];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \n "];
        yield 'comment only' => ['/* SELECT 1 */'];
    }

    #[DataProvider('otherStatementTypeProvider')]
    public function test_other_statement_types_never_reach_the_database(string $query): void
    {
        $this->assertRefusal(self::STATEMENT_TYPE, (new PGSQLSelectTool($this->untouchedConnection()))($query));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secondStatementProvider(): iterable
    {
        yield 'write after a select' => ['SELECT 1; DROP TABLE users'];
        yield 'write after a commented semicolon' => ['SELECT 1 /* ; */; DELETE FROM users'];
        yield 'copy after a select' => ["SELECT 1; COPY users TO '/tmp/users.csv'"];
        yield 'set after a select' => ['SELECT 1; SET ROLE postgres'];
        yield 'commit ending the read-only transaction' => ['SELECT 1; COMMIT; DELETE FROM users'];
        yield 'chained reads' => ['SELECT 1; SELECT 2'];
        yield 'two trailing semicolons' => ['SELECT 1;;'];
        yield 'semicolon inside a dollar-quoted string' => ['SELECT $$a;b$$'];
    }

    #[DataProvider('secondStatementProvider')]
    public function test_a_second_statement_never_reaches_the_database(string $query): void
    {
        $this->assertRefusal(self::ONE_STATEMENT, (new PGSQLSelectTool($this->untouchedConnection()))($query));
    }

    public function test_a_write_refused_by_the_database_is_returned_to_the_model(): void
    {
        $refusal = new PDOException('SQLSTATE[25006]: Read only sql transaction: 7 ERROR:  cannot execute DELETE in a read-only transaction');
        $refusal->errorInfo = ['25006', 7, 'ERROR:  cannot execute DELETE in a read-only transaction'];
        $query = 'EXPLAIN ANALYZE DELETE FROM users';

        $result = (new PGSQLSelectTool($this->connection($query, $this->statementThrowing($refusal))))($query);

        $this->assertRefusal(self::DATABASE_REFUSAL . 'ERROR:  cannot execute DELETE in a read-only transaction', $result);
        $this->assertSame(['exec: START TRANSACTION READ ONLY', 'prepare', 'rollBack'], $this->calls);
    }

    public function test_other_database_errors_escape_after_the_rollback(): void
    {
        $missingTable = new PDOException('SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "nope" does not exist');
        $missingTable->errorInfo = ['42P01', 7, 'ERROR:  relation "nope" does not exist'];
        $query = 'SELECT * FROM nope';
        $tool = new PGSQLSelectTool($this->connection($query, $this->statementThrowing($missingTable)));

        try {
            $tool($query);
            $this->fail('The database error should escape.');
        } catch (PDOException $exception) {
            $this->assertSame($missingTable, $exception);
        }

        $this->assertSame(['exec: START TRANSACTION READ ONLY', 'prepare', 'rollBack'], $this->calls);
    }

    public function test_an_open_transaction_is_refused_before_anything_runs(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('exec');
        $pdo->expects($this->never())->method('prepare');
        $pdo->expects($this->never())->method('rollBack');

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('pgsql_select_query cannot guarantee read-only access inside an open transaction: give it its own database connection.');

        (new PGSQLSelectTool($pdo))('SELECT 1');
    }

    public function test_a_read_only_transaction_that_cannot_start_fails_closed(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('exec')->willReturn(false);
        $pdo->expects($this->never())->method('prepare');

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('pgsql_select_query could not start a read-only transaction.');

        (new PGSQLSelectTool($pdo))('SELECT 1');
    }

    public function test_tool_schema(): void
    {
        $tool = new PGSQLSelectTool($this->createMock(PDO::class));

        $this->assertSame('pgsql_select_query', $tool->getName());
        $this->assertSame(['query'], $tool->getRequiredProperties());
    }

    public function test_rows_are_read_with_parameters_bound_as_data(): void
    {
        $tool = new PGSQLSelectTool($this->seededPostgres());

        $this->assertSame(
            [['name' => 'Ada'], ['name' => 'Grace']],
            $tool(
                'SELECT name FROM users WHERE id = :id OR name = :name ORDER BY id',
                [['name' => 'id', 'value' => '1'], ['name' => ':name', 'value' => 'Grace']]
            )
        );
        $this->assertSame([], $tool('SELECT name FROM users WHERE name = :name', [['name' => 'name', 'value' => "x' OR '1'='1'; DELETE FROM users; --"]]));
        $this->assertSame([['name' => 'Grace']], $tool('SELECT name FROM users WHERE name ILIKE :pattern', [['name' => 'pattern', 'value' => 'gr%']]));
        $this->assertSame([['total' => 2]], $this->withWarningsAsExceptions(fn (): array => $tool('SELECT COUNT(*) AS total FROM users', null)));
    }

    public function test_comment_markers_inside_literals_do_not_refuse_a_read(): void
    {
        $tool = new PGSQLSelectTool($this->seededPostgres());

        $this->assertSame(
            [['dashes' => '--', 'opener' => '/*']],
            $tool("SELECT '--' AS dashes, \$\$/*\$\$ AS opener -- trailing comment")
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeProvider(): iterable
    {
        yield 'TOOLS-01: line comment marker in a literal' => ["WITH a AS (SELECT '--'), d AS (DELETE FROM users RETURNING 1) SELECT * FROM d"];
        yield 'TOOLS-01: line comment marker in a dollar-quoted string' => ['WITH a AS (SELECT $$--$$), d AS (DELETE FROM users RETURNING 1) SELECT * FROM d'];
        yield 'TOOLS-01: line comment marker in a tagged dollar-quoted string' => ['WITH a AS (SELECT $q$--$q$), d AS (DELETE FROM users RETURNING 1) SELECT * FROM d'];
        yield 'TOOLS-09: comment glued between keyword and table' => ['WITH d AS (DELETE/**/FROM users RETURNING *) SELECT * FROM d'];
        yield 'TOOLS-06: select into' => ['SELECT * INTO stolen FROM users'];
        yield 'TOOLS-06: select into table' => ['SELECT * INTO TABLE stolen FROM users'];
        yield 'TOOLS-06: select into unlogged' => ['SELECT name INTO UNLOGGED stolen FROM users'];
        yield 'TOOLS-06: select into after a common table expression' => ['WITH u AS (SELECT * FROM users) SELECT * INTO stolen FROM u'];
        yield 'TOOLS-07: update glued to quoted identifiers' => ['WITH x AS (UPDATE"users"SET"name"=\'pwned\' RETURNING 1) SELECT * FROM x'];
        yield 'TOOLS-07: update separated by comments' => ['WITH x AS (UPDATE/**/users/**/SET/**/name=\'pwned\' RETURNING 1) SELECT * FROM x'];
        yield 'TOOLS-08: function that deletes' => ['SELECT purge_users()'];
        yield 'TOOLS-08: sequence change' => ["SELECT setval('users_id_seq', 1000)"];
        yield 'insert in a common table expression' => ["WITH i AS (INSERT INTO users (name) VALUES ('Eve') RETURNING id) SELECT * FROM i"];
        yield 'write inside explain analyze' => ['EXPLAIN ANALYZE DELETE FROM users'];
        yield 'locking read' => ['SELECT * FROM users FOR UPDATE'];
    }

    #[DataProvider('writeProvider')]
    public function test_writes_are_refused_by_the_database(string $query): void
    {
        $pdo = $this->seededPostgres();

        $result = (new PGSQLSelectTool($pdo))($query);

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith(self::DATABASE_REFUSAL, $result->getText());
        $this->assertSame([['name' => 'Ada'], ['name' => 'Grace']], $pdo->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertNull($pdo->query("SELECT to_regclass('stolen')")->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT last_value FROM users_id_seq')->fetchColumn());
    }

    public function test_session_settings_changed_by_a_query_are_rolled_back(): void
    {
        $pdo = $this->seededPostgres();

        (new PGSQLSelectTool($pdo))("SELECT set_config('search_path', 'pg_catalog', false)");

        $this->assertSame($this->postgres->schema, $pdo->query('SELECT current_schema()')->fetchColumn());
    }

    public function test_the_connection_stays_read_write_for_the_application(): void
    {
        $pdo = $this->seededPostgres();

        (new PGSQLSelectTool($pdo))('SELECT purge_users()');
        (new PGSQLSelectTool($pdo))('SELECT name FROM users');
        $pdo->exec("INSERT INTO users (name) VALUES ('Linus')");

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_an_application_transaction_is_left_untouched(): void
    {
        $pdo = $this->seededPostgres();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO users (name) VALUES ('Linus')");

        try {
            (new PGSQLSelectTool($pdo))('SELECT name FROM users');
            $this->fail('The tool should refuse to run inside the application transaction.');
        } catch (ToolException) {
        }

        $this->assertTrue($pdo->inTransaction());
        $pdo->commit();
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    protected function assertRefusal(string $message, mixed $result): void
    {
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame($message, $result->getText());
    }

    protected function untouchedConnection(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $pdo->expects($this->never())->method('prepare');

        return $pdo;
    }

    /**
     * A connection outside any transaction that records the calls the tool makes.
     */
    protected function connection(string $query, PDOStatement $statement): PDO
    {
        $inTransaction = false;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$inTransaction): int {
            $this->calls[] = "exec: {$sql}";
            $inTransaction = true;

            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($query, $statement): PDOStatement {
            $this->assertSame($query, $sql);
            $this->calls[] = 'prepare';

            return $statement;
        });
        $pdo->method('rollBack')->willReturnCallback(function () use (&$inTransaction): bool {
            $this->calls[] = 'rollBack';
            $inTransaction = false;

            return true;
        });

        return $pdo;
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    protected function statementReturning(array $rows): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($rows);

        return $statement;
    }

    protected function statementThrowing(PDOException $exception): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willThrowException($exception);

        return $statement;
    }

    protected function seededPostgres(): PDO
    {
        $this->postgres = PostgresSandbox::open();
        $pdo = $this->postgres->pdo;
        $pdo->exec('CREATE TABLE users (id serial PRIMARY KEY, name text)');
        $pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Grace')");
        $pdo->exec('CREATE FUNCTION purge_users() RETURNS bigint LANGUAGE sql AS $$ WITH d AS (DELETE FROM users RETURNING 1) SELECT count(*) FROM d $$');

        return $pdo;
    }
}
