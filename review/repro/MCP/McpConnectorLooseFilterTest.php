<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_is_list;
use function array_map;
use function array_values;

class McpConnectorLooseFilterTest extends TestCase
{
    public function test_only_allows_exactly_the_listed_names(): void
    {
        $tools = $this->connector(['100', '1e2', '100.0'])->only(['100'])->tools();

        $this->assertSame(['100'], $this->names($tools));
    }

    public function test_exclude_removes_exactly_the_listed_names(): void
    {
        $tools = $this->connector(['100', '1e2', '100.0'])->exclude(['100'])->tools();

        $this->assertSame(['1e2', '100.0'], $this->names($tools));
    }

    public function test_filtered_tools_are_a_list(): void
    {
        $tools = $this->connector(['read', 'write', 'delete'])->exclude(['write'])->tools();

        $this->assertTrue(array_is_list($tools));
        $this->assertSame(['read', 'delete'], $this->names($tools));
    }

    /**
     * @param string[] $names
     */
    protected function connector(array $names): McpConnector
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => array_map(fn (string $name): array => ['name' => $name], $names)]],
        );

        return McpConnector::make(['transport' => $transport]);
    }

    /**
     * @param ToolInterface[] $tools
     * @return string[]
     */
    protected function names(array $tools): array
    {
        return array_values(array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools));
    }
}
