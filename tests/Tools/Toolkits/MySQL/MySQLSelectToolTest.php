<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tests\Support\PhpWarningsAsExceptions;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MySQLSelectToolTest extends TestCase
{
    use PhpWarningsAsExceptions;

    protected const REJECTION = "The query was rejected for security reasons.
            It looks like you are trying to run a write query using the read-only query tool.";

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
        yield 'leading block comment' => ['/* report */ SELECT 1'];
        yield 'leading line comment' => ["-- report\nSELECT 1"];
        yield 'keywords as column name prefixes' => ['SELECT created_at, updated_at, deleted_at, inserted_by FROM users'];
        yield 'keywords as column name suffixes' => ['SELECT last_update, soft_delete, pre_insert, auto_replace FROM users'];
        yield 'union' => ['SELECT 1 UNION SELECT 2'];
        yield 'backticked reserved identifiers' => ['SELECT `key`, `value` FROM `order`'];
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

        $this->assertSame($rows, (new MySQLSelectTool($pdo))($query));
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
        yield 'create' => ['CREATE TABLE t (id INT)'];
        yield 'alter' => ['ALTER TABLE users ADD c INT'];
        yield 'truncate' => ['TRUNCATE users'];
        yield 'replace' => ['REPLACE INTO users VALUES (1)'];
        yield 'call' => ['CALL cleanup()'];
        yield 'grant' => ["GRANT ALL ON *.* TO 'x'@'%'"];
        yield 'set' => ['SET GLOBAL general_log = 1'];
        yield 'lock' => ['LOCK TABLES users WRITE'];
        yield 'load data' => ["LOAD DATA INFILE '/etc/passwd' INTO TABLE users"];
        yield 'handler' => ['HANDLER users OPEN'];
        yield 'lowercase write' => ['delete from users'];
        yield 'write after whitespace' => ["\n\t DROP TABLE users"];
        yield 'write hidden after a block comment' => ['/* SELECT */ DELETE FROM users'];
        yield 'write hidden after a line comment' => ["-- SELECT\nDELETE FROM users"];
        yield 'write statement after a select' => ['SELECT 1; DROP TABLE users'];
        yield 'write statement after a newline' => ["SELECT 1;\nDELETE FROM users"];
        yield 'write after a hash comment' => ["SELECT 1 # note\n; DELETE FROM users"];
        yield 'write in a common table expression' => ['WITH d AS (DELETE FROM users) SELECT 1'];
        yield 'write inside explain' => ['EXPLAIN ANALYZE DELETE FROM users'];
        yield 'select into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/users.csv'"];
        yield 'select into dumpfile' => ["SELECT 0x3c3f INTO DUMPFILE '/var/www/shell.php'"];
        yield 'select into variable' => ['SELECT id INTO @id FROM users'];
        yield 'load file' => ["SELECT LOAD_FILE('/etc/passwd')"];
        yield 'lowercase load file' => ["select load_file('/etc/passwd')"];
        yield 'locking read' => ['SELECT * FROM users FOR UPDATE'];
        yield 'execute prepared statement' => ['SELECT 1; EXECUTE stmt'];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \n "];
        yield 'comment only' => ['/* SELECT 1 */'];
    }

    #[DataProvider('rejectedQueryProvider')]
    public function test_rejected_query_never_reaches_the_database(string $query): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame(self::REJECTION, (new MySQLSelectTool($pdo))($query));
    }

    public function test_rejected_query_leaves_the_data_untouched(): void
    {
        $pdo = $this->seededDatabase();

        (new MySQLSelectTool($pdo))('DELETE FROM users');

        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_parameters_are_bound_with_or_without_the_colon_prefix(): void
    {
        $rows = (new MySQLSelectTool($this->seededDatabase()))(
            'SELECT name FROM users WHERE id = :id OR name = :name ORDER BY id',
            [['name' => 'id', 'value' => '1'], ['name' => ':name', 'value' => 'Grace']]
        );

        $this->assertSame([['name' => 'Ada'], ['name' => 'Grace']], $rows);
    }

    public function test_parameter_values_are_bound_as_data_not_sql(): void
    {
        $pdo = $this->seededDatabase();
        $payload = "x' OR '1'='1'; DELETE FROM users; --";

        $rows = (new MySQLSelectTool($pdo))(
            'SELECT name FROM users WHERE name = :name',
            [['name' => 'name', 'value' => $payload]]
        );

        $this->assertSame([], $rows);
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_null_parameters_bind_nothing(): void
    {
        $tool = new MySQLSelectTool($this->seededDatabase());

        $rows = $this->withWarningsAsExceptions(fn (): array => $tool('SELECT COUNT(*) AS total FROM users', null));

        $this->assertSame([['total' => 2]], $rows);
    }

    public function test_tool_schema(): void
    {
        $tool = new MySQLSelectTool($this->createMock(PDO::class));

        $this->assertSame('mysql_select_query', $tool->getName());
        $this->assertSame(['query'], $tool->getRequiredProperties());
    }

    protected function seededDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Ada'), (2, 'Grace')");

        return $pdo;
    }
}
