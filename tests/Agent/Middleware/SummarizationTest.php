<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;

class SummarizationTest extends TestCase
{
    /**
     * Run the middleware over a chat history and return the resulting messages.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function summarize(array $messages, int $messagesToKeep): array
    {
        $history = new InMemoryChatHistory();
        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        $provider = new FakeAIProvider(new AssistantMessage('Summary'));
        // maxTokens: 1 makes any non-empty history exceed the threshold
        $middleware = new Summarization($provider, maxTokens: 1, messagesToKeep: $messagesToKeep);
        $middleware->before(new ChatNode($provider, $history), new AIInferenceEvent(), new AgentState());

        return $history->getMessages();
    }

    /**
     * @return array{ToolCallMessage, ToolResultMessage}
     */
    protected function toolCallPair(): array
    {
        $call = new ToolCall('search', 'call_1', ['query' => 'PHP']);

        return [
            new ToolCallMessage(tools: [$call]),
            new ToolResultMessage([(clone $call)->setResult('Results for: PHP')]),
        ];
    }

    /**
     * @param Message[] $messages
     * @return array<int, string|null>
     */
    protected function contents(array $messages): array
    {
        return array_map(fn (Message $message): ?string => $message->getContent(), $messages);
    }

    public function test_cutoff_on_user_message_moves_back_to_previous_assistant_message(): void
    {
        // The target cutoff (index 2) is a UserMessage: keeping it right after the
        // UserMessage summary would produce two consecutive user messages.
        $messages = $this->summarize([
            new UserMessage('Question 1'),
            new AssistantMessage('Answer 1'),
            new UserMessage('Question 2'),
            new AssistantMessage('Answer 2'),
        ], messagesToKeep: 2);

        $this->assertCount(4, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertStringContainsString('Summary', (string) $messages[0]->getContent());
        $this->assertSame(['Answer 1', 'Question 2', 'Answer 2'], $this->contents(array_slice($messages, 1)));
    }

    public function test_cutoff_on_assistant_message_keeps_the_requested_messages(): void
    {
        $messages = $this->summarize([
            new UserMessage('Question 1'),
            new AssistantMessage('Answer 1'),
            new UserMessage('Question 2'),
            new AssistantMessage('Answer 2'),
            new UserMessage('Question 3'),
            new AssistantMessage('Answer 3'),
        ], messagesToKeep: 3);

        $this->assertCount(4, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertStringContainsString('Summary', (string) $messages[0]->getContent());
        $this->assertSame(['Answer 2', 'Question 3', 'Answer 3'], $this->contents(array_slice($messages, 1)));
    }

    public function test_cutoff_preserves_tool_call_pairs_and_role_alternation(): void
    {
        // The target cutoff (index 4) is the tool result, index 3 is the tool call and
        // index 2 is a UserMessage: the first safe cutoff is the assistant message at index 1.
        $messages = $this->summarize([
            new UserMessage('Question 1'),
            new AssistantMessage('Answer 1'),
            new UserMessage('Search for PHP'),
            ...$this->toolCallPair(),
            new AssistantMessage('Answer 2'),
            new UserMessage('Question 3'),
            new AssistantMessage('Answer 3'),
        ], messagesToKeep: 4);

        $this->assertCount(8, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertStringContainsString('Summary', (string) $messages[0]->getContent());
        $this->assertSame('Answer 1', $messages[1]->getContent());
        $this->assertSame('Search for PHP', $messages[2]->getContent());
        $this->assertInstanceOf(ToolCallMessage::class, $messages[3]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[4]);
        $this->assertSame(['Answer 2', 'Question 3', 'Answer 3'], $this->contents(array_slice($messages, 5)));
    }

    public function test_history_is_left_untouched_when_no_safe_cutoff_exists(): void
    {
        $original = [
            new UserMessage('Search for PHP'),
            ...$this->toolCallPair(),
            new AssistantMessage('Answer 1'),
        ];

        $messages = $this->summarize($original, messagesToKeep: 2);

        $this->assertSame($original, $messages);
    }
}
