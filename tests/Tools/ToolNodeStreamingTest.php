<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class ToolNodeStreamingTest extends TestCase
{
    public function test_tool_node_streams_chunks_and_returns_final_event(): void
    {
        // Create two simple tools
        $tool1 = Tool::make('calculator', 'Performs calculations')
            ->setCallable(function (): int {
                return 8; // 5 + 3
            })
            ->setInputs([]);

        $tool2 = Tool::make('greeter', 'Greets a person')
            ->setCallable(fn (): string => "Hello, World!")
            ->setInputs([]);

        // Create the ToolCallMessage
        $toolCallMessage = new ToolCallMessage(null, [$tool1, $tool2]);

        // Create the agent state with chat history
        // Add a user message and tool call message (required for valid message sequence)
        $chatHistory = new InMemoryChatHistory();
        $chatHistory->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Test user message'));
        $chatHistory->addMessage($toolCallMessage);
        $state = (new AgentState())->setChatHistory($chatHistory);

        // Create the events
        $inferenceEvent = new AIInferenceEvent('Test instructions', []);
        $toolCallEvent = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        // Create the ToolNode
        $toolNode = new ToolNode();
        $toolNode->setWorkflowContext($state, $inferenceEvent);

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
