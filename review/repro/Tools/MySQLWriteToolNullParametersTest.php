<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use ErrorException;
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;
use PDO;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;

class MySQLWriteToolNullParametersTest extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function test_null_parameters_are_treated_as_none(): void
    {
        $result = (new MySQLWriteTool($this->pdo))('DELETE FROM users', null);

        $this->assertSame('Query executed successfully. 0 row(s) affected.', $result);
    }

    public function test_omitted_parameters_through_tool_execution_are_treated_as_none(): void
    {
        $tool = new MySQLWriteTool($this->pdo);
        $tool->setInputs(['query' => 'DELETE FROM users']);

        $tool->execute();

        $this->assertSame('Query executed successfully. 0 row(s) affected.', $tool->getResult());
    }
}
