<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MySQLSelectExecutableCommentTest extends TestCase
{
    protected const REJECTION = "The query was rejected for security reasons.
            It looks like you are trying to run a write query using the read-only query tool.";

    public static function writesHiddenInExecutableComments(): iterable
    {
        yield 'versioned INTO OUTFILE' => ["SELECT * FROM users /*!50000 INTO OUTFILE '/var/www/html/users.txt' */"];
        yield 'unversioned INTO DUMPFILE' => ["SELECT * FROM users /*! INTO DUMPFILE '/tmp/users' */"];
        yield 'stacked DROP' => ['SELECT 1; /*!DROP TABLE users*/'];
        yield 'comment marker inside a string literal' => ["SELECT '/*' INTO OUTFILE '/tmp/x' FROM users WHERE '*/' = '*/'"];
        yield 'double dash without space is subtraction in MySQL' => ["SELECT 1--1 INTO OUTFILE '/tmp/x'"];
        yield 'MariaDB stacked DELETE' => ['SELECT 1; /*M!100100 DELETE FROM users */'];
    }

    #[DataProvider('writesHiddenInExecutableComments')]
    public function test_write_inside_a_mysql_executable_comment_is_rejected(string $query): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame(self::REJECTION, (new MySQLSelectTool($pdo))($query));
    }

    public function test_plain_comments_and_optimizer_hints_are_still_accepted(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->assertSame([['1' => 1]], (new MySQLSelectTool($pdo))('/* report */ SELECT /*+ hint */ 1'));
    }
}
