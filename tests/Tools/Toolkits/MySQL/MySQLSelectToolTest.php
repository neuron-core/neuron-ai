<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\PhpWarningsAsExceptions;
use NeuronAI\Tests\Tools\Toolkits\MySQL\Stub\MySQLSandbox;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MySQLSelectToolTest extends TestCase
{
    use PhpWarningsAsExceptions;

    protected const STATEMENT_TYPE = 'Start the query with one of these keywords, with nothing before it: SELECT, WITH, SHOW, DESCRIBE, EXPLAIN.';

    protected const ONE_STATEMENT = "Send one statement per call. Pass values that contain ';' as parameters.";

    protected const FILE_ACCESS = 'Reading or writing server files (OUTFILE, DUMPFILE, LOAD_FILE) is not allowed.';

    /**
     * @var string[]
     */
    protected array $calls = [];

    protected ?MySQLSandbox $sandbox = null;

    protected function tearDown(): void
    {
        $this->sandbox?->drop();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readQueryProvider(): iterable
    {
        yield 'select' => ['SELECT id, name FROM users WHERE id = :id'];
        yield 'lowercase select' => ['select * from users'];
        yield 'mixed case select' => ['SeLeCt 1'];
        yield 'leading whitespace and newlines' => ["\n\t  SELECT 1"];
        yield 'common table expression' => ['WITH recent AS (SELECT id FROM users) SELECT * FROM recent'];
        yield 'show' => ['SHOW TABLES'];
        yield 'describe' => ['DESCRIBE users'];
        yield 'explain' => ['EXPLAIN SELECT * FROM users'];
        yield 'trailing semicolon' => ['SELECT 1 AS one; '];
        yield 'optimizer hint after the keyword' => ['SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1'];
        yield 'keywords as column name prefixes' => ['SELECT created_at, updated_at, deleted_at, inserted_by FROM users'];
        yield 'keywords as column name suffixes' => ['SELECT last_update, soft_delete, pre_insert, auto_replace FROM users'];
        yield 'write keywords inside a literal' => ["SELECT * FROM notes WHERE body LIKE '%delete me%'"];
        yield 'replace string function' => ["SELECT REPLACE(name, 'a', 'b') FROM users"];
        yield 'union' => ['SELECT 1 UNION SELECT 2'];
        yield 'backticked reserved identifiers' => ['SELECT `key`, `value` FROM `order`'];
    }

    #[DataProvider('readQueryProvider')]
    public function test_read_query_runs_verbatim_in_a_read_only_transaction_that_is_rolled_back(string $query): void
    {
        $rows = [['id' => 1, 'name' => 'Ada']];

        $result = (new MySQLSelectTool($this->connection($query, $this->statementReturning($rows))))($query);

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
        yield 'create' => ['CREATE TABLE t (id INT)'];
        yield 'alter' => ['ALTER TABLE users ADD c INT'];
        yield 'truncate' => ['TRUNCATE users'];
        yield 'replace' => ['REPLACE INTO users VALUES (1)'];
        yield 'call' => ['CALL cleanup()'];
        yield 'grant' => ["GRANT ALL ON *.* TO 'x'@'%'"];
        yield 'set' => ['SET GLOBAL general_log = 1'];
        yield 'lock' => ['LOCK TABLES users WRITE'];
        yield 'kill' => ['KILL 42'];
        yield 'load data' => ["LOAD DATA INFILE '/etc/passwd' INTO TABLE users"];
        yield 'handler' => ['HANDLER users OPEN'];
        yield 'do' => ['DO SLEEP(10)'];
        yield 'lowercase write' => ['delete from users'];
        yield 'write after whitespace' => ["\n\t DROP TABLE users"];
        yield 'leading block comment' => ['/* report */ SELECT 1'];
        yield 'leading line comment' => ["-- report\nSELECT 1"];
        yield 'write hidden after a block comment' => ['/* SELECT */ DELETE FROM users'];
        yield 'write hidden after a line comment' => ["-- SELECT\nDELETE FROM users"];
        yield 'write in a leading executable comment' => ['/*!DELETE FROM users*/'];
        yield 'keyword glued to an identifier' => ['SELECTED FROM t'];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \n "];
        yield 'comment only' => ['/* SELECT 1 */'];
    }

    #[DataProvider('otherStatementTypeProvider')]
    public function test_other_statement_types_never_reach_the_database(string $query): void
    {
        $this->assertRefusal(self::STATEMENT_TYPE, (new MySQLSelectTool($this->untouchedConnection()))($query));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secondStatementProvider(): iterable
    {
        yield 'write after a select' => ['SELECT 1; DROP TABLE users'];
        yield 'write after a newline' => ["SELECT 1;\nDELETE FROM users"];
        yield 'commit ending the read-only transaction' => ['SELECT 1; COMMIT; DELETE FROM users'];
        yield 'write after a hash comment' => ["SELECT 1 # note\n; DELETE FROM users"];
        yield 'two trailing semicolons' => ['SELECT 1;;'];
        yield 'semicolon inside a literal' => ["SELECT * FROM users WHERE name = 'a;b'"];
        yield 'TOOLS-01: line comment marker in a single-quoted literal' => ["SELECT '--' AS a; DELETE FROM users"];
        yield 'TOOLS-01: block comment markers in literals' => ["SELECT '/*' AS a; DELETE FROM users; SELECT '*/'"];
        yield 'TOOLS-01: line comment marker in a double-quoted literal' => ['SELECT "--"; DELETE FROM users'];
        yield 'TOOLS-01: escaped quote before a comment marker' => ["SELECT 'a\\' -- '; DELETE FROM users"];
        yield 'TOOLS-01: comment glued between keyword and table' => ['SELECT 1; DELETE/**/FROM users'];
        yield 'TOOLS-01: double minus without a space is arithmetic' => ['SELECT 1--1; DELETE FROM users'];
        yield 'TOOLS-01: separator inside an executable comment' => ['SELECT 1 /*!50000 ; DELETE FROM users */'];
        yield 'TOOLS-04: grant' => ["SELECT 1; GRANT ALL ON *.* TO 'attacker'@'%'"];
        yield 'TOOLS-04: set global' => ['SELECT 1; SET GLOBAL general_log = 1'];
        yield 'TOOLS-04: lock tables' => ['SELECT 1; LOCK TABLES users WRITE'];
        yield 'TOOLS-04: rename table' => ['SELECT 1; RENAME TABLE users TO users_old'];
        yield 'TOOLS-04: execute a prepared statement' => ['SELECT 1; EXECUTE stmt'];
        yield 'TOOLS-05: drop in an executable comment' => ['SELECT 1; /*!DROP TABLE users*/'];
        yield 'TOOLS-05: delete in a MariaDB executable comment' => ['SELECT 1; /*M!100100 DELETE FROM users */'];
    }

    #[DataProvider('secondStatementProvider')]
    public function test_a_second_statement_never_reaches_the_database(string $query): void
    {
        $this->assertRefusal(self::ONE_STATEMENT, (new MySQLSelectTool($this->untouchedConnection()))($query));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fileAccessProvider(): iterable
    {
        yield 'into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/users.csv'"];
        yield 'into dumpfile' => ["SELECT 0x3c3f INTO DUMPFILE '/var/www/shell.php'"];
        yield 'load file' => ["SELECT LOAD_FILE('/etc/passwd')"];
        yield 'lowercase load file' => ["select load_file('/etc/passwd')"];
        yield 'keyword inside a literal' => ["SELECT * FROM notes WHERE body = 'outfile'"];
        yield 'TOOLS-05: versioned executable comment' => ["SELECT * FROM users /*!50000 INTO OUTFILE '/var/www/html/users.txt' */"];
        yield 'TOOLS-05: unversioned executable comment' => ["SELECT * FROM users /*! INTO DUMPFILE '/tmp/users' */"];
        yield 'TOOLS-05: comment marker inside a literal' => ["SELECT '/*' INTO OUTFILE '/tmp/x' FROM users WHERE '*/' = '*/'"];
        yield 'TOOLS-05: double minus without a space' => ["SELECT 1--1 INTO OUTFILE '/tmp/x'"];
    }

    #[DataProvider('fileAccessProvider')]
    public function test_server_file_access_never_reaches_the_database(string $query): void
    {
        $this->assertRefusal(self::FILE_ACCESS, (new MySQLSelectTool($this->untouchedConnection()))($query));
    }

    public function test_database_errors_are_returned_to_the_model_after_the_rollback(): void
    {
        $missingTable = "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'app.nope' doesn't exist";
        $query = 'SELECT * FROM nope';

        $result = (new MySQLSelectTool($this->connection($query, $this->statementThrowing(new PDOException($missingTable)))))($query);

        $this->assertRefusal($missingTable, $result);
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
        $this->expectExceptionMessage('mysql_select_query cannot guarantee read-only access inside an open transaction: give it its own database connection.');

        (new MySQLSelectTool($pdo))('SELECT 1');
    }

    public function test_a_read_only_transaction_that_cannot_start_fails_closed(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('exec')->willReturn(false);
        $pdo->expects($this->never())->method('prepare');

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('mysql_select_query could not start a read-only transaction.');

        (new MySQLSelectTool($pdo))('SELECT 1');
    }

    public function test_tool_schema(): void
    {
        $tool = new MySQLSelectTool($this->createMock(PDO::class));

        $this->assertSame('mysql_select_query', $tool->getName());
        $this->assertSame(['query'], $tool->getRequiredProperties());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function serverProvider(): iterable
    {
        yield 'mysql' => [3306];
        yield 'mariadb' => [3307];
    }

    #[DataProvider('serverProvider')]
    public function test_rows_are_read_with_parameters_bound_as_data(int $port): void
    {
        $pdo = $this->seededDatabase($port);
        $tool = new MySQLSelectTool($pdo);

        $this->assertSame(
            [['name' => 'Ada'], ['name' => 'Grace']],
            $tool(
                'SELECT name FROM users WHERE id = :id OR name = :name ORDER BY id',
                [['name' => 'id', 'value' => '1'], ['name' => ':name', 'value' => 'Grace']]
            )
        );
        $this->assertSame([], $tool('SELECT name FROM users WHERE name = :name', [['name' => 'name', 'value' => "x' OR '1'='1'; DELETE FROM users; --"]]));
        $this->assertSame([['total' => 2]], $this->withWarningsAsExceptions(fn (): array => $tool('SELECT COUNT(*) AS total FROM users', null)));
    }

    #[DataProvider('serverProvider')]
    public function test_a_write_through_a_function_is_refused_by_the_database(int $port): void
    {
        $pdo = $this->seededDatabase($port);

        $result = (new MySQLSelectTool($pdo))('SELECT purge_users()');

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('SQLSTATE[25006]', $result->getText());
        $this->assertSame(2, $this->countUsers($pdo));
    }

    #[DataProvider('serverProvider')]
    public function test_the_connection_stays_read_write_for_the_application(int $port): void
    {
        $pdo = $this->seededDatabase($port);

        (new MySQLSelectTool($pdo))('SELECT purge_users()');
        (new MySQLSelectTool($pdo))('SELECT name FROM users');
        $pdo->exec("INSERT INTO users (name) VALUES ('Linus')");

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(3, $this->countUsers($pdo));
    }

    #[DataProvider('serverProvider')]
    public function test_an_application_transaction_is_left_untouched(int $port): void
    {
        $pdo = $this->seededDatabase($port);
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO users (name) VALUES ('Linus')");

        try {
            (new MySQLSelectTool($pdo))('SELECT name FROM users');
            $this->fail('The tool should refuse to run inside the application transaction.');
        } catch (ToolException) {
        }

        $this->assertTrue($pdo->inTransaction());
        $pdo->commit();
        $this->assertSame(3, $this->countUsers($pdo));
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

    protected function seededDatabase(int $port): PDO
    {
        $this->sandbox = MySQLSandbox::open($port);
        $pdo = $this->sandbox->pdo;
        $pdo->exec('CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))');
        $pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Grace')");
        $pdo->exec('CREATE FUNCTION purge_users() RETURNS INT DETERMINISTIC MODIFIES SQL DATA BEGIN DELETE FROM users; RETURN 1; END');

        return $pdo;
    }

    protected function countUsers(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
