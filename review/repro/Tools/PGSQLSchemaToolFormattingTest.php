<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSchemaTool;
use PHPUnit\Framework\TestCase;

class PGSQLSchemaToolFormattingTest extends TestCase
{
    protected PostgresSandbox $postgres;

    protected function setUp(): void
    {
        $this->postgres = PostgresSandbox::open();
        $this->postgres->pdo->exec('CREATE TABLE articles (id int PRIMARY KEY, title text, tags text[]); CREATE INDEX articles_lower_title_idx ON articles (lower(title))');
    }

    protected function tearDown(): void
    {
        $this->postgres->drop();
    }

    public function test_array_columns_get_the_array_hint(): void
    {
        $this->assertStringContainsString('has array column `tags`', (new PGSQLSchemaTool($this->postgres->pdo))());
    }

    public function test_expression_index_does_not_report_the_function_name_as_a_column(): void
    {
        $this->assertStringNotContainsString('on `articles` (lower)', (new PGSQLSchemaTool($this->postgres->pdo))());
    }

    public function test_empty_table_filter_is_not_announced_as_a_filter(): void
    {
        $this->assertStringNotContainsString('(filtered to specified tables)', (new PGSQLSchemaTool($this->postgres->pdo, []))());
    }
}
