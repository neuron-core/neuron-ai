<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Tests\Agent\Nodes\Stub\CalculatorTool;
use NeuronAI\Tests\Agent\Nodes\Stub\GreeterTool;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

class ToolNodeStreamingTest extends TestCase
{
    public function test_tool_node_streams_chunks_and_returns_final_event(): void
    {
        // Two simple registry tools and their calls
        $registry = [new CalculatorTool(), new GreeterTool()];
        $call1 = ToolCall::make('calculator', 'call_1', []);
        $call2 = ToolCall::make('greeter', 'call_2', []);

        // Create the ToolCallMessage
        $toolCallMessage = new ToolCallMessage(null, [$call1, $call2]);

        // Create the agent state with chat history
        // Add a user message and tool call message (required for valid message sequence)
        $chatHistory = new InMemoryChatHistory();
        $chatHistory->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Test user message'));
        $chatHistory->addMessage($toolCallMessage);
        $state = new AgentState();

        // Create the events
        $inferenceEvent = new AIInferenceEvent('Test instructions', $registry);
        $toolCallEvent = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        // Create the ToolNode (the executor hands the node the event it dispatches)
        $toolNode = new ToolNode($chatHistory);
        $toolNode->setWorkflowContext(new NodeContext($state, $toolCallEvent));

        // Invoke the node and collect yielded chunks
        $generator = $toolNode->__invoke($toolCallEvent, $state);

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        // Get the return value
        $returnValue = $generator->getReturn();

        // Assertions
        // 1. Should have 4 chunks total (ToolCallChunk + ToolResultChunk for each tool)
        $this->assertCount(4, $chunks);

        // 2. First chunk should be ToolCallChunk for tool1
        $this->assertInstanceOf(ToolCallChunk::class, $chunks[0]);
        $this->assertSame('calculator', $chunks[0]->tool->getName());

        // 3. Second chunk should be ToolResultChunk for tool1
        $this->assertInstanceOf(ToolResultChunk::class, $chunks[1]);
        $this->assertSame('calculator', $chunks[1]->tool->getName());
        $this->assertEquals(8, $chunks[1]->tool->getResult()); // 5 + 3 = 8

        // 4. Third chunk should be ToolCallChunk for tool2
        $this->assertInstanceOf(ToolCallChunk::class, $chunks[2]);
        $this->assertSame('greeter', $chunks[2]->tool->getName());

        // 5. Fourth chunk should be ToolResultChunk for tool2
        $this->assertInstanceOf(ToolResultChunk::class, $chunks[3]);
        $this->assertSame('greeter', $chunks[3]->tool->getName());
        $this->assertEquals('Hello, World!', $chunks[3]->tool->getResult());

        // 6. Return value should be the AIInferenceEvent
        $this->assertInstanceOf(AIInferenceEvent::class, $returnValue);
        $this->assertSame($inferenceEvent, $returnValue);
    }
}
