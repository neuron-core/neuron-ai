<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

class TokenCounterTest extends TestCase
{
    private TokenCounter $tokenCounter;

    protected function setUp(): void
    {
        $this->tokenCounter = new TokenCounter();
    }

    public function test_counts_tokens_for_empty_message_array(): void
    {
        $result = $this->tokenCounter->count([]);

        $this->assertSame(0, $result);
    }

    public function test_counts_tokens_for_simple_string_content(): void
    {
        $message = new UserMessage('Hello world');

        $result = $this->tokenCounter->count([$message]);

        $this->assertSame(14, $result);
    }

    public function test_counts_tokens_for_null_content(): void
    {
        $message = new UserMessage(null);

        $result = $this->tokenCounter->count([$message]);

        $this->assertSame(5, $result);
    }

    public function test_counts_tokens_for_array_content(): void
    {
        $message = new UserMessage('Hello');

        // Content: JSON encoded array [{"text":"Hello","type":"message"}] = 35 chars
        // Role: "user" = 4 chars
        // Total chars: 39
        // Tokens from chars: ceil(39 / 4.0) = 10
        // Extra tokens per message: 3
        // Total: 10 + 3 = 13
        $result = $this->tokenCounter->count([$message]);

        $this->assertSame(12, $result);
    }

    public function test_counts_tokens_for_multiple_messages(): void
    {
        $messages = [
            new UserMessage('Hi'),
            new AssistantMessage('Hello there')
        ];

        $result = $this->tokenCounter->count($messages);

        $this->assertSame(27, $result);
    }

    public function test_counts_tokens_for_tool_call_message_with_array_content(): void
    {
        $tool = $this->createMockTool('test_tool', ['param' => 'value']);
        $message = new ToolCallMessage(tools: [$tool]);
        $messages = [$message];

        $result = $this->tokenCounter->count($messages);

        $this->assertSame(12, $result);
    }

    public function test_counts_tokens_with_custom_chars_per_token_ratio(): void
    {
        $tokenCounter = new TokenCounter(2.0, 3.0);
        $message = new UserMessage('Hello');
        $messages = [$message];

        // Content: "Hello" = 5 chars
        // Role: "user" = 4 chars
        // Total chars: 9
        // Tokens from chars: ceil(9 / 2.0) = 5
        // Extra tokens per message: 3
        // Total: 5 + 3 = 8
        $result = $tokenCounter->count($messages);

        $this->assertSame(21, $result);
    }

    public function test_counts_tokens_with_custom_extra_tokens_per_message(): void
    {
        $tokenCounter = new TokenCounter(4.0, 5.0);
        $message = new UserMessage('Test');
        $messages = [$message];

        $result = $tokenCounter->count($messages);

        $this->assertSame(14, $result);
    }

    public function test_counts_tokens_with_fractional_extra_tokens(): void
    {
        $tokenCounter = new TokenCounter(4.0, 2.5);
        $messages = [
            new UserMessage('Hi'),
            new UserMessage('Bye')
        ];

        // Message 1: "Hi" (2) + "user" (4) = 6 chars = ceil(6/4) + 2.5 = 2 + 2.5 = 4.5
        // Message 2: "Bye" (3) + "user" (4) = 7 chars = ceil(7/4) + 2.5 = 2 + 2.5 = 4.5
        // Total: 4.5 + 4.5 = 9.0, final ceil = 9
        $result = $tokenCounter->count($messages);

        $this->assertSame(23, $result);
    }

    public function test_handles_empty_tools_array_in_tool_call_message(): void
    {
        $message = new ToolCallMessage(tools: []);
        $messages = [$message];

        // Content: "No tools" = 8 chars
        // Role: "assistant" = 9 chars
        // Tools: empty array, no additional chars
        // Total chars: 17
        // Tokens from chars: ceil(17 / 4.0) = 5
        // Extra tokens per message: 3
        // Total: 5 + 3 = 8
        $result = $this->tokenCounter->count($messages);

        $this->assertSame(6, $result);
    }

    public function test_handles_tool_without_id_in_result_message(): void
    {
        $tool = $this->createMockToolWithoutId('test_tool');
        $message = new ToolResultMessage([$tool]);
        $messages = [$message];

        $result = $this->tokenCounter->count($messages);

        $this->assertSame(22, $result);
    }

    private function createMockTool(string $name, array $inputs = []): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getCallId')->willReturn('call_123');
        $tool->method('getName')->willReturn($name);
        $tool->method('getInputs')->willReturn($inputs);

        return $tool;
    }

    private function createMockToolWithoutId(string $name): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('jsonSerialize')->willReturn([
            'name' => $name,
            'description' => 'Test tool',
            'inputs' => [],
            'callId' => null,
            'result' => null,
        ]);

        return $tool;
    }
}
