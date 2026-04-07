<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\MissingCallbackParameter;
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
}
