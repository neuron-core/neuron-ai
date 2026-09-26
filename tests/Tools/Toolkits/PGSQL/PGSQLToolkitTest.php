<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSchemaTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLToolkit;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;
use NeuronAI\Tools\ToolInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function array_map;

class PGSQLToolkitTest extends TestCase
{
    public function test_provides_schema_select_and_write_tools(): void
    {
        $tools = PGSQLToolkit::make($this->createMock(PDO::class))->tools();

        $this->assertSame(
            [PGSQLSchemaTool::class, PGSQLSelectTool::class, PGSQLWriteTool::class],
            array_map(fn (ToolInterface $tool): string => $tool::class, $tools)
        );
        $this->assertSame(
            ['analyze_pgsql_database_schema', 'pgsql_select_query', 'pgsql_write_query'],
            array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools)
        );
    }

    public function test_write_tool_can_be_left_out_for_read_only_agents(): void
    {
        $tools = PGSQLToolkit::make($this->createMock(PDO::class))->exclude([PGSQLWriteTool::class])->tools();

        $this->assertSame(
            ['analyze_pgsql_database_schema', 'pgsql_select_query'],
            array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools)
        );
    }

    public function test_guidelines_name_the_database(): void
    {
        $this->assertStringContainsString('SQL queries for PostgreSQL', (string) PGSQLToolkit::make($this->createMock(PDO::class))->guidelines());
    }
}
