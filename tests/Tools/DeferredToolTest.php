<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Tests\Tools\Stub\DeferredToolStub;
use NeuronAI\Tests\Tools\Stub\InvokableDeferredTool;
use NeuronAI\Tools\DeferredTool;
use NeuronAI\Tools\DeferredToolInterface;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

class DeferredToolTest extends TestCase
{
    public function test_constructs_a_tool_from_an_input_schema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'description' => 'Search query', 'minLength' => 1]],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
        $tool = new DeferredTool('external_lookup', 'Search the application.', $schema);

        $this->assertInstanceOf(DeferredToolInterface::class, $tool);
        $this->assertSame('external_lookup', $tool->getName());
        $this->assertSame('Search the application.', $tool->getDescription());
        $this->assertSame($schema, $tool->getInputSchema());
        $this->assertSame(['query'], $tool->getRequiredProperties());
        $properties = $tool->getProperties();
        $this->assertCount(1, $properties);
        $this->assertInstanceOf(ToolProperty::class, $properties[0]);
        $this->assertSame('query', $properties[0]->getName());
        $this->assertSame(PropertyType::STRING, $properties[0]->getType());
        $this->assertSame('Search query', $properties[0]->getDescription());
        $this->assertTrue($properties[0]->isRequired());
        $this->assertSame($properties, $tool->getProperties());
    }

    public function test_constructs_nested_properties_from_the_input_schema(): void
    {
        $tool = new DeferredTool('select', inputSchema: [
            'type' => 'object',
            'properties' => ['selection' => [
                'type' => 'object',
                'properties' => ['ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ]],
                'required' => ['ids'],
            ]],
            'required' => ['selection'],
        ]);

        $selection = $tool->getProperties()[0];
        $this->assertInstanceOf(ObjectProperty::class, $selection);
        $this->assertTrue($selection->isRequired());
        $ids = $selection->getProperties()[0];
        $this->assertInstanceOf(ArrayProperty::class, $ids);
        $this->assertTrue($ids->isRequired());
        $this->assertSame(PropertyType::INTEGER, $ids->getItems()->getType());
    }

    public function test_explicit_empty_schema_exposes_no_properties(): void
    {
        $tool = new DeferredToolStub(inputSchema: ['type' => 'object']);
        $this->assertSame([], $tool->getProperties());
        $this->assertSame([], $tool->getRequiredProperties());
    }

    public function test_concrete_tool_can_use_the_existing_property_builder(): void
    {
        $tool = DeferredTool::make('external_lookup');
        $tool->addProperty(new ToolProperty('query', PropertyType::STRING, required: true));

        $this->assertNull($tool->getDescription());
        $this->assertSame([
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ], $tool->getInputSchema());
    }

    public function test_explicit_schema_rejects_property_additions(): void
    {
        $tool = new DeferredTool('external_lookup', inputSchema: ['type' => 'object']);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('already has an explicit input schema');

        $tool->addProperty(new ToolProperty('query', PropertyType::STRING));
    }

    public function test_schema_only_tool_maps_to_a_provider_definition(): void
    {
        $tool = DeferredToolStub::make();

        $this->assertInstanceOf(DeferredToolInterface::class, $tool);
        $this->assertSame([
            [
                'type' => 'function',
                'function' => [
                    'name' => 'external_lookup',
                    'description' => 'Look up information in the external application.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'The search query.',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ], (new ToolMapper())->map([$tool]));
    }

    public function test_deferred_tool_keeps_the_existing_approval_contract(): void
    {
        $tool = new DeferredToolStub();
        $tool->withApprovalPolicy(
            fn (ToolInterface $tool): bool|string => $tool->getInput('query') === 'sensitive'
                ? 'Approve access to sensitive information.'
                : false,
        );

        $tool->setInputs(['query' => 'sensitive']);
        $this->assertSame('Approve access to sensitive information.', $tool->requiresApproval($tool->getInputs()));

        $tool->suppressApproval();
        $this->assertFalse($tool->requiresApproval($tool->getInputs()));
        $this->assertInstanceOf(DeferredToolInterface::class, $tool);
    }

    public function test_schema_only_tool_rejects_backend_execution(): void
    {
        $tool = DeferredToolStub::make()->setInputs(['query' => 'weather']);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage(
            'Tool "external_lookup" requires external execution: submit its result instead of executing it on the backend.'
        );

        $tool->execute();
    }

    public function test_backend_execution_cannot_invoke_a_deferred_tool_body(): void
    {
        $tool = new InvokableDeferredTool();
        $tool->setInputs(['query' => 'weather']);

        try {
            $tool->execute();
            $this->fail('Deferred tools must reject backend execution.');
        } catch (ToolException) {
            $this->assertFalse($tool->invoked);
            $this->assertFalse($tool->hasResult());
        }
    }
}
