<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Providers\OpenAI\Responses\ToolMapper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class OpenAIResponsesToolMapperTest extends TestCase
{
    private function createTool(string $name, string $description): Tool
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
}
