<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use NeuronAI\Providers\OpenAI\Responses\ToolMapper;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class OpenAIResponsesToolMapperTest extends TestCase
{
    protected function createTool(string $name, string $description): Tool
    {
        return new class ($name, $description) extends Tool {
            public function __construct(string $name, string $description)
            {
                $this->name = $name;
                $this->description = $description;
            }

            public function __invoke(): string
            {
                return '';
            }
        };
    }

    public function test_tool_with_raw_parameters(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The product SKU'],
            ],
            'required' => ['sku'],
        ];

        $tool = $this->createTool('get_stock', 'Get the current stock level for a SKU.')
            ->setParameters(['parameters' => $schema]);

        $mapping = (new ToolMapper())->map([$tool]);

        $this->assertSame([
            [
                'type' => 'function',
                'name' => 'get_stock',
                'description' => 'Get the current stock level for a SKU.',
                'parameters' => $schema,
            ],
        ], $mapping);
    }

    public function test_tool_with_properties(): void
    {
        $tool = $this->createTool('get_stock', 'Get the current stock level for a SKU.')
            ->addProperty(new ToolProperty('sku', PropertyType::STRING, 'The product SKU', true));

        $mapping = (new ToolMapper())->map([$tool]);

        $this->assertSame([
            [
                'type' => 'function',
                'name' => 'get_stock',
                'description' => 'Get the current stock level for a SKU.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => ['type' => 'string', 'description' => 'The product SKU'],
                    ],
                    'required' => ['sku'],
                ],
            ],
        ], $mapping);
    }

    public function test_provider_tools_map_type_options_and_optional_name(): void
    {
        $mapping = (new ToolMapper())->map([
            new ProviderTool('web_search', options: ['search_context_size' => 'low']),
            new ProviderTool('mcp', 'deepwiki', ['server_url' => 'https://mcp.deepwiki.com/mcp']),
        ]);

        $this->assertSame([
            ['type' => 'web_search', 'search_context_size' => 'low'],
            ['type' => 'mcp', 'server_url' => 'https://mcp.deepwiki.com/mcp', 'name' => 'deepwiki'],
        ], $mapping);
    }
}
