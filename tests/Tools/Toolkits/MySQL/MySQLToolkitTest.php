<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLToolkit;
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;
use NeuronAI\Tools\ToolInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function array_map;

class MySQLToolkitTest extends TestCase
{
    public function test_provides_schema_select_and_write_tools(): void
    {
        $tools = MySQLToolkit::make($this->createMock(PDO::class))->tools();

        $this->assertSame(
            [MySQLSchemaTool::class, MySQLSelectTool::class, MySQLWriteTool::class],
            array_map(fn (ToolInterface $tool): string => $tool::class, $tools)
        );
        $this->assertSame(
            ['analyze_mysql_database_schema', 'mysql_select_query', 'mysql_write_query'],
            array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools)
        );
    }

    public function test_write_tool_can_be_left_out_for_read_only_agents(): void
    {
        $tools = MySQLToolkit::make($this->createMock(PDO::class))->exclude([MySQLWriteTool::class])->tools();

        $this->assertSame(
            ['analyze_mysql_database_schema', 'mysql_select_query'],
            array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools)
        );
    }

    public function test_guidelines_name_the_database(): void
    {
        $this->assertStringContainsString('SQL queries for MySQL', (string) MySQLToolkit::make($this->createMock(PDO::class))->guidelines());
    }
}
