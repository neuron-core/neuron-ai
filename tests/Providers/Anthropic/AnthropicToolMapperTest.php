<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use NeuronAI\Providers\Anthropic\ToolMapper;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\TestCase;

use function array_column;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class AnthropicToolMapperTest extends TestCase
{
    public function test_provider_tools_map_type_options_and_name(): void
    {
        $mapped = (new ToolMapper())->map([
            new ProviderTool('web_search_20250305', 'web_search', ['max_uses' => 3]),
            new ProviderTool('code_execution_20250522'),
        ]);

        $this->assertSame([
            ['type' => 'web_search_20250305', 'max_uses' => 3, 'name' => 'web_search'],
            ['type' => 'code_execution_20250522'],
        ], $mapped);
    }

    public function test_tool_parameters_are_merged_into_the_definition(): void
    {
        $tool = (new ToolStub('lookup', 'Look up'))->setParameters(['cache_control' => ['type' => 'ephemeral']]);

        $mapped = (new ToolMapper())->map([$tool]);

        $this->assertSame('lookup', $mapped[0]['name']);
        $this->assertSame('Look up', $mapped[0]['description']);
        $this->assertSame(['type' => 'ephemeral'], $mapped[0]['cache_control']);
        $this->assertSame('{"type":"object","properties":{},"required":[]}', json_encode($mapped[0]['input_schema'], JSON_THROW_ON_ERROR));
    }

    public function test_tool_parameters_take_precedence_over_the_generated_definition(): void
    {
        $schema = ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']], 'required' => ['sku']];
        $tool = (new ToolStub('lookup', 'Look up'))->setParameters(['input_schema' => $schema]);

        $mapped = (new ToolMapper())->map([$tool]);

        $this->assertSame([['name' => 'lookup', 'description' => 'Look up', 'input_schema' => $schema]], $mapped);
    }

    public function test_local_and_provider_tools_keep_their_registration_order(): void
    {
        $mapped = (new ToolMapper())->map([
            new ToolStub('first'),
            new ProviderTool('web_search_20250305', 'web_search'),
            new ToolStub('last'),
        ]);

        $this->assertSame(['first', 'web_search', 'last'], array_column($mapped, 'name'));
    }
}
