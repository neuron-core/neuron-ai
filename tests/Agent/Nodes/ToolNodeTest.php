<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tests\Agent\Tools\TestParametrizedTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;
use Throwable;

class ToolNodeTest extends TestCase
{
    /**
     * Test that MissingCallbackParameter is caught by error handler
     * and the tool result is set directly via setResult().
     */
    public function test_error_handler_catches_missing_required_parameter(): void
    {
        // Create a tool with a required property
        $tool = Tool::make('test_tool', 'A test tool')
            ->addProperty(new ToolProperty('required_input', PropertyType::STRING, 'A required input', true))
            ->setCallable(fn (string $required_input): string => "Processed: {$required_input}");

        // Set inputs WITHOUT the required property - this will trigger MissingCallbackParameter
        $tool->setCallId('call_1');
        $tool->setInputs([]); // Missing 'required_input'

        // Create the ToolNode with an error handler
        $errorHandler = fn (Throwable $e, \NeuronAI\Tools\ToolInterface $tool): string => "Error handled: {$e->getMessage()}";

        $toolNode = new ToolNode(
            maxRuns: 10,
            errorHandler: $errorHandler
        );

        // Set up the state and event
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(
            instructions: 'Test instructions',
            tools: [$tool]
        );
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        // Set up the workflow context (required for Node::emit() to work)
        $toolNode->setWorkflowContext($state, $event);

        // Execute the ToolNode - should NOT throw because error handler catches it
        $generator = $toolNode($event, $state);

        // Consume the generator to execute the node
        foreach ($generator as $_) {
            // Just iterate through
        }

        // Assert the tool result was set to the error handler's message
        $this->assertSame(
            'Error handled: Missing required parameter: required_input',
            $tool->getResult()
        );
    }

    /**
     * Test that MissingCallbackParameter escapes when no error handler is set.
     */
    public function test_missing_required_parameter_throws_without_error_handler(): void
    {
        // Create a tool with a required property
        $tool = Tool::make('test_tool', 'A test tool')
            ->addProperty(new ToolProperty('required_input', PropertyType::STRING, 'A required input', true))
            ->setCallable(fn (string $required_input): string => "Processed: {$required_input}");

        // Set inputs WITHOUT the required property
        $tool->setCallId('call_1');
        $tool->setInputs([]); // Missing 'required_input'

        // Create the ToolNode WITHOUT an error handler
        $toolNode = new ToolNode(
            maxRuns: 10
        );

        // Set up the state and event
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(
            instructions: 'Test instructions',
            tools: [$tool]
        );
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        // Set up the workflow context (required for Node::emit() to work)
        $toolNode->setWorkflowContext($state, $event);

        // Expect the exception to be thrown
        $this->expectException(MissingCallbackParameter::class);
        $this->expectExceptionMessage('Missing required parameter: required_input');

        // Execute the ToolNode
        $generator = $toolNode($event, $state);

        // Consume the generator
        foreach ($generator as $_) {
            // Just iterate through
        }
    }

    public function test_parameterized_tool_tracked_by_run_key(): void
    {
        $tool = new TestParametrizedTool('param_tool', 'offset=0');

        $toolNode = new ToolNode(maxRuns: 2);
        $state = new AgentState();

        // First execution
        $tool->setCallId('call_1');
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);
        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        // Second execution with same parameters
        $tool->setCallId('call_2');
        $toolCallMessage2 = new ToolCallMessage(null, [$tool]);
        $inferenceEvent2 = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event2 = new ToolCallEvent($toolCallMessage2, $inferenceEvent2);
        $toolNode->setWorkflowContext($state, $event2);
        foreach ($toolNode($event2, $state) as $_) {
        }

        $this->assertSame(2, $state->getToolRuns('param_tool:offset=0'));
        $this->assertSame(0, $state->getToolRuns('param_tool')); // Name tracking unused
    }

    public function test_different_parameters_tracked_separately(): void
    {
        $tool1 = new TestParametrizedTool('read_tool', 'offset=0');
        $tool2 = new TestParametrizedTool('read_tool', 'offset=100');

        $toolNode = new ToolNode(maxRuns: 1);

        // First call with offset=0
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool1]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool1]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);
        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        // Second call with offset=100 - should succeed (different key)
        $tool2->setCallId('call_2');
        $toolCallMessage2 = new ToolCallMessage(null, [$tool2]);
        $inferenceEvent2 = new AIInferenceEvent(instructions: 'Test', tools: [$tool2]);
        $event2 = new ToolCallEvent($toolCallMessage2, $inferenceEvent2);
        $toolNode->setWorkflowContext($state, $event2);
        foreach ($toolNode($event2, $state) as $_) {
        }

        $this->assertSame(1, $state->getToolRuns('read_tool:offset=0'));
        $this->assertSame(1, $state->getToolRuns('read_tool:offset=100'));
    }

    public function test_regular_tool_tracked_by_name(): void
    {
        $tool = Tool::make('regular_tool', 'A regular tool')
            ->setCallable(fn (): string => 'result');
        $tool->setCallId('call_1');
        $tool->setInputs([]);

        $toolNode = new ToolNode(maxRuns: 2);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext($state, $event);

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'));
        $this->assertSame(0, $state->getToolRuns('regular_tool:any_key'));
    }

    public function test_max_runs_enforced_per_run_key(): void
    {
        $tool = new TestParametrizedTool('bounded_tool', 'id=123');

        $toolNode = new ToolNode(maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext($state, $event);

        // First call succeeds
        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        // Second call with same parameters should throw
        $this->expectException(ToolRunsExceededException::class);
        $this->expectExceptionMessage('Tool bounded_tool has been executed too many times - 1 - with arguments: []');

        $tool->setCallId('call_2');
        $toolCallMessage2 = new ToolCallMessage(null, [$tool]);
        $inferenceEvent2 = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event2 = new ToolCallEvent($toolCallMessage2, $inferenceEvent2);
        $toolNode->setWorkflowContext($state, $event2);

        foreach ($toolNode($event2, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }
    }

    public function test_different_parameters_allow_more_calls_than_max_runs(): void
    {
        $toolNode = new ToolNode(maxRuns: 1);
        $state = new AgentState();

        // Create tools with different parameters - each should get maxRuns calls
        $tools = [
            new TestParametrizedTool('multi_tool', 'id=1'),
            new TestParametrizedTool('multi_tool', 'id=2'),
            new TestParametrizedTool('multi_tool', 'id=3'),
        ];

        foreach ($tools as $index => $tool) {
            $tool->setCallId('call_' . $index);
            $toolCallMessage = new ToolCallMessage(null, [$tool]);
            $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
            $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
            $toolNode->setWorkflowContext($state, $event);

            foreach ($toolNode($event, $state) as $_) {
                $_ = null; // This is to prevent rector from removing it.
            }
        }

        // Each unique parameter combination was called once
        $this->assertSame(1, $state->getToolRuns('multi_tool:id=1'));
        $this->assertSame(1, $state->getToolRuns('multi_tool:id=2'));
        $this->assertSame(1, $state->getToolRuns('multi_tool:id=3'));
    }

}
