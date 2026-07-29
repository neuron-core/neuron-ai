<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PGSQLSelectToolTest extends TestCase
{
    private PGSQLSelectTool $tool;

    protected function setUp(): void
    {
        $this->tool = new PGSQLSelectTool(new PDO('sqlite::memory:'));
    }

    public function test_validate_read_only_accepts_select_queries(): void
    {
        $this->assertTrue($this->validateReadOnlyQuery('SELECT id, name FROM users WHERE id = :id'));
        $this->assertTrue($this->validateReadOnlyQuery('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function test_validate_read_only_rejects_write_queries(): void
    {
        $this->assertFalse($this->validateReadOnlyQuery('DROP TABLE users'));
        $this->assertFalse($this->validateReadOnlyQuery('UPDATE users SET name = :name WHERE id = 1'));
    }

    public function test_additional_checks_allow_chained_read_statements(): void
    {
        $this->assertTrue($this->performAdditionalSecurityChecks('SELECT 1; SELECT 2'));
    }

    public function test_additional_checks_reject_write_statement_after_semicolon(): void
    {
        $this->assertFalse($this->performAdditionalSecurityChecks('SELECT 1; DROP TABLE users'));
    }

    private function validateReadOnlyQuery(string $query): bool
    {
        return (new ReflectionMethod($this->tool, 'validateReadOnlyQuery'))->invoke($this->tool, $query);
    }

    private function performAdditionalSecurityChecks(string $query): bool
    {
        return (new ReflectionMethod($this->tool, 'performAdditionalSecurityChecks'))->invoke($this->tool, $query);
    }
}
