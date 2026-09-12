<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Providers\AWS\ToolMapper as BedrockToolMapper;
use NeuronAI\Providers\Anthropic\ToolMapper as AnthropicToolMapper;
use NeuronAI\Providers\Gemini\ToolMapper as GeminiToolMapper;
use NeuronAI\Providers\Ollama\ToolMapper as OllamaToolMapper;
use NeuronAI\Providers\OpenAI\Responses\ToolMapper as ResponsesToolMapper;
use NeuronAI\Providers\OpenAI\ToolMapper as OpenAIToolMapper;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ToolInputSchemaTest extends TestCase
{
    /** @return iterable<string, array{ToolMapperInterface, list<int|string>}> */
    public static function mappers(): iterable
    {
        yield 'OpenAI' => [new OpenAIToolMapper(), [0, 'function', 'parameters']];
        yield 'OpenAI Responses' => [new ResponsesToolMapper(), [0, 'parameters']];
        yield 'Anthropic' => [new AnthropicToolMapper(), [0, 'input_schema']];
        yield 'Bedrock' => [new BedrockToolMapper(), [0, 'toolSpec', 'inputSchema', 'json']];
        yield 'Ollama' => [new OllamaToolMapper(), [0, 'function', 'parameters']];
        yield 'Gemini' => [new GeminiToolMapper(), ['functionDeclarations', 0, 'parameters']];
    }

    /** @param list<int|string> $path */
    #[DataProvider('mappers')]
    public function test_deferred_schema_preserves_nested_properties_and_constraints(ToolMapperInterface $mapper, array $path): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'locations' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => ['latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90]],
                        'required' => ['latitude'],
                        'additionalProperties' => false,
                    ],
                ],
                'selection' => ['type' => 'string', 'enum' => ['current']],
            ],
            'required' => ['locations'],
            'additionalProperties' => false,
        ];
        $tool = new FrontendTool('get_locations', 'Read locations from the application.', $schema);

        $this->assertCount(2, $tool->getProperties());
        $this->assertSame($schema, $this->mappedSchema($mapper, $tool, $path));
    }

    /** @param list<int|string> $path */
    #[DataProvider('mappers')]
    public function test_property_based_tools_keep_their_schema(ToolMapperInterface $mapper, array $path): void
    {
        $tool = new ToolStub('lookup');
        $tool->addProperty(new ObjectProperty('location', required: true, properties: [
            new ToolProperty('city', PropertyType::STRING, required: true),
        ]));
        $tool->addProperty(new ToolProperty('limit', PropertyType::INTEGER));

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'location' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required' => ['city'],
                ],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['location'],
        ], $this->mappedSchema($mapper, $tool, $path));
    }

    /** @param list<int|string> $path */
    #[DataProvider('mappers')]
    public function test_empty_properties_are_encoded_as_an_object(ToolMapperInterface $mapper, array $path): void
    {
        $this->assertSame(
            '{"type":"object","properties":{},"required":[]}',
            json_encode($this->mappedSchema($mapper, new FrontendTool('get_location'), $path)),
        );
    }

    public function test_gemini_keeps_its_nullable_type_conversion_without_changing_the_tool_schema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'location' => ['type' => ['string', 'null']],
            ],
        ];
        $tool = new FrontendTool('lookup', inputSchema: $schema);
        $expected = $schema;
        $expected['properties']['location']['type'] = 'string';

        $this->assertSame($expected, $this->mappedSchema(new GeminiToolMapper(), $tool, ['functionDeclarations', 0, 'parameters']));
        $this->assertSame($schema, $tool->getInputSchema());
    }

    /**
     * @param list<int|string> $path
     * @return array<string, mixed>
     */
    protected function mappedSchema(ToolMapperInterface $mapper, ToolInterface $tool, array $path): array
    {
        $schema = $mapper->map([$tool]);
        foreach ($path as $key) {
            $schema = $schema[$key];
        }

        return $schema;
    }
}
