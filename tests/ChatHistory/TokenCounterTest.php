<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
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

        // Content: "Hello world" = 11 chars
        // Role: "user" = 4 chars
        // Total chars: 15
        // Tokens from chars: ceil(15 / 4.0) = 4
        // Extra tokens per message: 3
        // Total: 4 + 3 = 7
        $result = $this->tokenCounter->count([$message]);

        $this->assertSame(7, $result);
    }

    public function test_counts_tokens_for_null_content(): void
    {
        $message = new UserMessage(null);

        // Content: null = 0 chars
        // Role: "user" = 4 chars
        // Total chars: 4
        // Tokens from chars: ceil(4 / 4.0) = 1
        // Extra tokens per message: 3
        // Total: 1 + 3 = 4
        $result = $this->tokenCounter->count([$message]);

        $this->assertSame(4, $result);
    }

    public function test_counts_tokens_for_array_content(): void
    {
        $content = [['text' => 'Hello', 'type' => 'message']];
        $message = new UserMessage($content);

        // Content: JSON encoded array [{"text":"Hello","type":"message"}] = 35 chars
        // Role: "user" = 4 chars
        // Total chars: 39
        // Tokens from chars: ceil(39 / 4.0) = 10
        // Extra tokens per message: 3
        // Total: 10 + 3 = 13
        $result = $this->tokenCounter->count([$message]);

        $this->assertSame(13, $result);
    }

    public function test_counts_tokens_for_multiple_messages(): void
    {
        $messages = [
            new UserMessage('Hi'),
            new AssistantMessage('Hello there')
        ];

        // Message 1: "Hi" (2) + "user" (4) = 6 chars = ceil(6/4) + 3 = 2 + 3 = 5
        // Message 2: "Hello there" (11) + "assistant" (9) = 20 chars = ceil(20/4) + 3 = 5 + 3 = 8
        // Total: 5 + 8 = 13
        $result = $this->tokenCounter->count($messages);

        $this->assertSame(13, $result);
    }

    public function test_counts_tokens_for_tool_call_message_with_string_content(): void
    {
        $tool = $this->createMockTool('test_tool', ['param' => 'value']);
        $message = new ToolCallMessage('Calling tool', [$tool]);

        $result = $this->tokenCounter->count([$message]);

        // Content: "Calling tool" = 12 chars
        // Role: "assistant" = 9 chars
        // Call Id: call_123 = 8 chars
        // Inputs: {'param':'value'} = 17 chars
        // Total chars: 49
        // Tokens from chars: ceil(49 / 4.0) = 13
        // Extra tokens per message: 3
        // Total: 13 + 3 = 16
        $this->assertSame(15, $result);
    }

    public function test_counts_tokens_for_tool_call_message_with_array_content(): void
    {
        $tool = $this->createMockTool('test_tool', ['param' => 'value']);
        $content = [['text' => 'Using tool', 'type' => 'tool_usage']];
        $message = new ToolCallMessage($content, [$tool]);
        $messages = [$message];

        $result = $this->tokenCounter->count($messages);

        // Content: [{"text":"Using tool","type":"tool_usage"}] = 43 chars
        // Role: "assistant" = 9 chars
        // Inputs: {'param':'value'} = 17 chars
        // Call Id: call_123 = 8 chars
        // Total chars: 77
        // Tokens from chars: ceil(77 / 4.0) = 20
        // Extra tokens per message: 3
        // Total: 20 + 3 = 23
        $this->assertSame(23, $result);
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

        $this->assertSame(8, $result);
    }

    public function test_counts_tokens_with_custom_extra_tokens_per_message(): void
    {
        $tokenCounter = new TokenCounter(4.0, 5.0);
        $message = new UserMessage('Test');
        $messages = [$message];

        // Content: "Test" = 4 chars
        // Role: "user" = 4 chars
        // Total chars: 8
        // Tokens from chars: ceil(8 / 4.0) = 2
        // Extra tokens per message: 5
        // Total: 2 + 5 = 7
        $result = $tokenCounter->count($messages);

        $this->assertSame(7, $result);
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

        $this->assertSame(9, $result);
    }

    public function test_counts_tokens_for_numeric_content(): void
    {
        $message = new UserMessage(42);
        $messages = [$message];

        // Content: "42" = 2 chars
        // Role: "user" = 4 chars
        // Total chars: 6
        // Tokens from chars: ceil(6 / 4.0) = 2
        // Extra tokens per message: 3
        // Total: 2 + 3 = 5
        $result = $this->tokenCounter->count($messages);

        $this->assertSame(5, $result);
    }

    public function test_counts_tokens_for_float_content(): void
    {
        $message = new UserMessage(3.14);
        $messages = [$message];

        // Content: "3.14" = 4 chars
        // Role: "user" = 4 chars
        // Total chars: 8
        // Tokens from chars: ceil(8 / 4.0) = 2
        // Extra tokens per message: 3
        // Total: 2 + 3 = 5
        $result = $this->tokenCounter->count($messages);

        $this->assertSame(5, $result);
    }

    public function test_handles_empty_tools_array_in_tool_call_message(): void
    {
        $message = new ToolCallMessage('No tools', []);
        $messages = [$message];

        // Content: "No tools" = 8 chars
        // Role: "assistant" = 9 chars
        // Tools: empty array, no additional chars
        // Total chars: 17
        // Tokens from chars: ceil(17 / 4.0) = 5
        // Extra tokens per message: 3
        // Total: 5 + 3 = 8
        $result = $this->tokenCounter->count($messages);

        $this->assertSame(8, $result);
    }

    public function test_handles_tool_without_id_in_result_message(): void
    {
        $tool = $this->createMockToolWithoutId('test_tool');
        $message = new ToolCallResultMessage([$tool]);
        $messages = [$message];

        // Content: null = 0 chars
        // Role: "user" = 4 chars
        // Tool IDs: no ID present, 0 chars
        // Total chars: 4
        // Tokens from chars: ceil(4 / 4.0) = 1
        // Extra tokens per message: 3
        // Total: 1 + 3 = 4
        $result = $this->tokenCounter->count($messages);

        $this->assertSame(4, $result);
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
