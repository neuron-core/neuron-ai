<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use NeuronAI\Providers\Gemini\ToolMapper;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class GeminiToolMapperTest extends TestCase
{
    public function test_provider_tools_map_to_a_list_of_empty_objects_or_their_options(): void
    {
        $mapped = (new ToolMapper())->map([
            new ProviderTool('google_search'),
            new ProviderTool('url_context'),
            new ProviderTool('code_execution', options: ['timeout' => 30]),
        ]);

        $this->assertSame(
            '[{"google_search":{}},{"url_context":{}},{"code_execution":{"timeout":30}}]',
            json_encode($mapped, JSON_THROW_ON_ERROR),
        );
    }

    public function test_function_declarations_take_precedence_over_provider_tools(): void
    {
        $mapped = (new ToolMapper())->map([
            new ToolStub('lookup', 'Look something up'),
            new ProviderTool('google_search'),
        ]);

        $this->assertSame(
            '{"functionDeclarations":[{"name":"lookup","description":"Look something up","parameters":{"type":"object","properties":{},"required":[]}}]}',
            json_encode($mapped, JSON_THROW_ON_ERROR),
        );
    }

    public function test_tool_parameters_extend_the_declaration(): void
    {
        $tool = (new ToolStub('notify', 'Send a notification'))->setParameters(['behavior' => 'NON_BLOCKING']);

        $declaration = (new ToolMapper())->map([$tool])['functionDeclarations'][0];

        $this->assertSame('NON_BLOCKING', $declaration['behavior']);
        $this->assertSame('notify', $declaration['name']);
    }

    public function test_nullable_types_are_stripped_in_nested_objects_and_array_items(): void
    {
        $schema = [
            'type' => ['object', 'null'],
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => ['zip' => ['type' => ['null', 'string']]],
                ],
                'tags' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
            ],
        ];

        $parameters = (new ToolMapper())->map([new FrontendTool('save', 'Save', $schema)])['functionDeclarations'][0]['parameters'];

        $this->assertSame('object', $parameters['type']);
        $this->assertSame('string', $parameters['properties']['address']['properties']['zip']['type']);
        $this->assertSame('string', $parameters['properties']['tags']['items']['type']);
    }
}
