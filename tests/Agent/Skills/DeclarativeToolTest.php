<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Skills;

use NeuronAI\Agent\Skills\DeclarativeToolBuilder;
use NeuronAI\Agent\Skills\Tools\ToolResult;
use NeuronAI\Tools\PropertyType;
use PHPUnit\Framework\TestCase;

class DeclarativeToolTest extends TestCase
{
    // --- ToolResult ---

    public function test_result_success(): void
    {
        $result = new ToolResult(0, 'ok');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('ok', $result->output);
        $this->assertNull($result->error);
        $this->assertSame([], $result->metadata);
    }

    public function test_result_failure_with_nonzero_exit_code(): void
    {
        $result = new ToolResult(1, '', 'command failed');

        $this->assertFalse($result->isSuccess());
    }

    public function test_result_failure_with_error_message(): void
    {
        $result = new ToolResult(0, 'partial', 'timeout');

        $this->assertFalse($result->isSuccess());
    }

    public function test_result_to_array_minimal(): void
    {
        $result = new ToolResult(0, 'done');

        $this->assertSame([
            'exit_code' => 0,
            'output' => 'done',
        ], $result->toArray());
    }

    public function test_result_to_array_includes_error(): void
    {
        $result = new ToolResult(1, '', 'command not found');

        $this->assertSame([
            'exit_code' => 1,
            'output' => '',
            'error' => 'command not found',
        ], $result->toArray());
    }

    public function test_result_to_array_includes_metadata(): void
    {
        $result = new ToolResult(0, 'ok', null, ['duration_ms' => 120]);

        $this->assertSame([
            'exit_code' => 0,
            'output' => 'ok',
            'metadata' => ['duration_ms' => 120],
        ], $result->toArray());
    }

    public function test_result_to_array_omits_null_error_and_empty_metadata(): void
    {
        $result = new ToolResult(0, 'output');

        $array = $result->toArray();
        $this->assertArrayNotHasKey('error', $array);
        $this->assertArrayNotHasKey('metadata', $array);
    }

    // --- DeclarativeToolBuilder: parseInputType ---

    public function test_parse_input_type_plain(): void
    {
        $result = DeclarativeToolBuilder::parseInputType('string');

        $this->assertSame('string', $result['type']);
        $this->assertSame([], $result['enum']);
    }

    public function test_parse_input_type_with_enum(): void
    {
        $result = DeclarativeToolBuilder::parseInputType('string [standard, express, economy]');

        $this->assertSame('string', $result['type']);
        $this->assertSame(['standard', 'express', 'economy'], $result['enum']);
    }

    public function test_parse_input_type_integer(): void
    {
        $result = DeclarativeToolBuilder::parseInputType('integer');

        $this->assertSame('integer', $result['type']);
        $this->assertSame([], $result['enum']);
    }

    public function test_parse_input_type_with_description(): void
    {
        $result = DeclarativeToolBuilder::parseInputType('string # City name in Chinese');

        $this->assertSame('string', $result['type']);
        $this->assertSame([], $result['enum']);
        $this->assertSame('City name in Chinese', $result['description']);
    }

    public function test_parse_input_type_with_enum_and_description(): void
    {
        $result = DeclarativeToolBuilder::parseInputType('string [standard, express, economy] # Shipping method');

        $this->assertSame('string', $result['type']);
        $this->assertSame(['standard', 'express', 'economy'], $result['enum']);
        $this->assertSame('Shipping method', $result['description']);
    }

    public function test_parse_input_type_without_description_returns_null(): void
    {
        $result = DeclarativeToolBuilder::parseInputType('string');

        $this->assertNull($result['description']);
    }

    public function test_parse_input_type_description_extracts_type_correctly(): void
    {
        $result = DeclarativeToolBuilder::parseInputType('integer # Page number starting from 1');

        $this->assertSame('integer', $result['type']);
        $this->assertSame([], $result['enum']);
        $this->assertSame('Page number starting from 1', $result['description']);
    }

    // --- DeclarativeToolBuilder: mapType ---

    public function test_map_type_string(): void
    {
        $this->assertSame(PropertyType::STRING, DeclarativeToolBuilder::mapType('string'));
    }

    public function test_map_type_integer(): void
    {
        $this->assertSame(PropertyType::INTEGER, DeclarativeToolBuilder::mapType('integer'));
    }

    public function test_map_type_int_alias(): void
    {
        $this->assertSame(PropertyType::INTEGER, DeclarativeToolBuilder::mapType('int'));
    }

    public function test_map_type_number(): void
    {
        $this->assertSame(PropertyType::NUMBER, DeclarativeToolBuilder::mapType('number'));
    }

    public function test_map_type_float_alias(): void
    {
        $this->assertSame(PropertyType::NUMBER, DeclarativeToolBuilder::mapType('float'));
    }

    public function test_map_type_boolean(): void
    {
        $this->assertSame(PropertyType::BOOLEAN, DeclarativeToolBuilder::mapType('boolean'));
    }

    public function test_map_type_bool_alias(): void
    {
        $this->assertSame(PropertyType::BOOLEAN, DeclarativeToolBuilder::mapType('bool'));
    }

    public function test_map_type_array(): void
    {
        $this->assertSame(PropertyType::ARRAY, DeclarativeToolBuilder::mapType('array'));
    }

    public function test_map_type_unknown_defaults_to_string(): void
    {
        $this->assertSame(PropertyType::STRING, DeclarativeToolBuilder::mapType('unknown'));
    }

    public function test_map_type_case_insensitive(): void
    {
        $this->assertSame(PropertyType::INTEGER, DeclarativeToolBuilder::mapType('INTEGER'));
        $this->assertSame(PropertyType::STRING, DeclarativeToolBuilder::mapType('STRING'));
    }

    // --- DeclarativeToolBuilder: parsePolicyValue ---

    public function test_parse_policy_value_true(): void
    {
        $this->assertTrue(DeclarativeToolBuilder::parsePolicyValue('true'));
    }

    public function test_parse_policy_value_false(): void
    {
        $this->assertFalse(DeclarativeToolBuilder::parsePolicyValue('false'));
    }

    public function test_parse_policy_value_integer(): void
    {
        $this->assertSame(5, DeclarativeToolBuilder::parsePolicyValue('5'));
    }

    public function test_parse_policy_value_string(): void
    {
        $this->assertSame('always', DeclarativeToolBuilder::parsePolicyValue('always'));
    }

    // --- DeclarativeToolBuilder: parseToolsSection ---

    public function test_parse_tools_section_single_tool(): void
    {
        $yaml = <<<'YAML'
- name: check_status
  type: shell
  description: Check API health
  execution:
    command: curl -s {{url}}
  input_schema:
    url: string
YAML;

        $definitions = DeclarativeToolBuilder::parseToolsSection($yaml);

        $this->assertCount(1, $definitions);
        $this->assertSame('check_status', $definitions[0]->name);
        $this->assertSame('shell', $definitions[0]->type);
        $this->assertSame('Check API health', $definitions[0]->description);
        $this->assertSame(['url' => 'string'], $definitions[0]->inputSchema);
        $this->assertSame(['command' => 'curl -s {{url}}'], $definitions[0]->execution);
    }

    public function test_parse_tools_section_with_policy(): void
    {
        $yaml = <<<'YAML'
- name: deploy
  type: shell
  description: Deploy the app
  execution:
    command: ./deploy.sh
  policy:
    idempotent: true
    side_effect: false
    max_calls: 3
    retry_on_failure: true
YAML;

        $definitions = DeclarativeToolBuilder::parseToolsSection($yaml);

        $this->assertCount(1, $definitions);
        $policy = $definitions[0]->policy;
        $this->assertNotNull($policy);
        $this->assertTrue($policy->idempotent);
        $this->assertFalse($policy->sideEffect);
        $this->assertSame(3, $policy->maxCalls);
        $this->assertTrue($policy->retryOnFailure);
    }

    public function test_parse_tools_section_empty(): void
    {
        $this->assertSame([], DeclarativeToolBuilder::parseToolsSection(''));
    }

    public function test_parse_tools_section_multiple_tools(): void
    {
        $yaml = <<<'YAML'
- name: tool_a
  type: shell
  description: First tool
  input_schema:
    x: integer
- name: tool_b
  type: http
  description: Second tool
  input_schema:
    y: string
YAML;

        $definitions = DeclarativeToolBuilder::parseToolsSection($yaml);

        $this->assertCount(2, $definitions);
        $this->assertSame('tool_a', $definitions[0]->name);
        $this->assertSame('tool_b', $definitions[1]->name);
    }
}
