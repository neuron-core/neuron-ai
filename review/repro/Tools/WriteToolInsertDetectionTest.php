<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WriteToolInsertDetectionTest extends TestCase
{
    public static function insertSpellings(): iterable
    {
        yield 'lowercase' => ["insert into users (name) values ('Ada')"];
        yield 'leading whitespace' => ["\n  INSERT INTO users (name) VALUES ('Ada')"];
        yield 'leading comment' => ["/* seed */ INSERT INTO users (name) VALUES ('Ada')"];
    }

    #[DataProvider('insertSpellings')]
    public function test_any_insert_spelling_reports_the_last_insert_id(string $query): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $this->assertSame(
            'Query executed successfully. 1 row(s) affected. Last insert ID: 1',
            (new MySQLWriteTool($pdo))($query)
        );
    }

    public function test_insert_of_no_rows_does_not_report_a_stale_insert_id(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users (name) VALUES ('Ada')");

        $this->assertSame(
            'Query executed successfully. 0 row(s) affected.',
            (new MySQLWriteTool($pdo))('INSERT INTO users (name) SELECT name FROM users WHERE 0')
        );
    }
}

// --- Companion Postgres repro: tests/Tools/Toolkits/PGSQL/WriteToolInsertDetectionTest.php ---
// namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;
// use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
// use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;
// use PHPUnit\Framework\TestCase;
//
// class WriteToolInsertDetectionTest extends TestCase
// {
//     protected ?PostgresSandbox $postgres = null;
//
//     protected function tearDown(): void
//     {
//         $this->postgres?->drop();
//     }
//
//     public function test_lowercase_insert_reports_the_new_id_on_postgres(): void
//     {
//         $this->postgres = PostgresSandbox::open();
//         $this->postgres->pdo->exec('CREATE TABLE users (id serial PRIMARY KEY, name text)');
//         $this->assertSame('Query executed successfully. 1 row(s) affected. Last insert ID: 1',
//             (new PGSQLWriteTool($this->postgres->pdo))("insert into users (name) values ('Ada')"));
//     }
//
//     public function test_insert_of_no_rows_does_not_report_a_stale_insert_id_on_postgres(): void
//     {
//         $this->postgres = PostgresSandbox::open();
//         $this->postgres->pdo->exec("CREATE TABLE users (id serial PRIMARY KEY, name text); INSERT INTO users (name) VALUES ('Ada')");
//         $this->assertSame('Query executed successfully. 0 row(s) affected.',
//             (new PGSQLWriteTool($this->postgres->pdo))('INSERT INTO users (name) SELECT name FROM users WHERE false'));
//     }
//
//     // Current code: PDOException SQLSTATE[55000] "lastval is not yet defined in this session" thrown after the row is written.
//     public function test_insert_into_a_table_without_sequence_in_a_fresh_session_on_postgres(): void
//     {
//         $this->postgres = PostgresSandbox::open();
//         $this->postgres->pdo->exec('CREATE TABLE tags (label text)');
//         $this->assertSame('Query executed successfully. 1 row(s) affected.',
//             (new PGSQLWriteTool($this->postgres->pdo))("INSERT INTO tags (label) VALUES ('php')"));
//     }
// }
