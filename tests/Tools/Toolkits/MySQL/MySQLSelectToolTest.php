<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MySQLSelectToolTest extends TestCase
{
    private MySQLSelectTool $tool;

    protected function setUp(): void
    {
        $this->tool = new MySQLSelectTool(new PDO('sqlite::memory:'));
    }

    public function test_get_first_keyword_extracts_and_uppercases(): void
    {
        $this->assertSame('SELECT', $this->getFirstKeyword('SELECT id FROM users'));
        $this->assertSame('WITH', $this->getFirstKeyword('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function test_get_first_keyword_normalizes_lowercase_and_leading_whitespace(): void
    {
        // Case is normalized by strtoupper(), not by a regex modifier.
        $this->assertSame('SELECT', $this->getFirstKeyword('   select * from users'));
    }

    public function test_get_first_keyword_returns_empty_when_no_word_found(): void
    {
        $this->assertSame('', $this->getFirstKeyword(''));
        $this->assertSame('', $this->getFirstKeyword('   '));
    }

    public function test_validate_read_only_accepts_select_queries(): void
    {
        $this->assertTrue($this->validateReadOnly('SELECT id, name FROM users WHERE id = :id'));
        $this->assertTrue($this->validateReadOnly('select * from users'));
    }

    public function test_validate_read_only_rejects_write_queries(): void
    {
        $this->assertFalse($this->validateReadOnly('DROP TABLE users'));
        $this->assertFalse($this->validateReadOnly('DELETE FROM users WHERE id = 1'));
    }

    private function getFirstKeyword(string $query): string
    {
        return (new ReflectionMethod($this->tool, 'getFirstKeyword'))->invoke($this->tool, $query);
    }

    private function validateReadOnly(string $query): bool
    {
        return (new ReflectionMethod($this->tool, 'validateReadOnly'))->invoke($this->tool, $query);
    }
}
