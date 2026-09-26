<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
use PHPUnit\Framework\Attributes\TestWith;
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
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        $provider = new FakeAIProvider(new AssistantMessage('Summary'));
        // maxTokens: 1 makes any non-empty history exceed the threshold
        $middleware = new Summarization($provider, maxTokens: 1, messagesToKeep: $messagesToKeep);
        $middleware->before(new ChatNode(), new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $history, $provider));

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

    public function test_a_freshly_loaded_conversation_is_summarized_on_its_first_inference(): void
    {
        $store = new InMemoryMessageStore();
        $previousSegment = new ChatHistory($store, 'thread');
        $previousSegment->addMessage(new UserMessage('Question 1'));
        $previousSegment->addMessage((new AssistantMessage('Answer 1'))->setUsage(new Usage(40, 10)));
        $previousSegment->addMessage(new UserMessage('Question 2'));
        $previousSegment->addMessage((new AssistantMessage('Answer 2'))->setUsage(new Usage(80, 20)));

        // The next segment opens its own history and summarizes before any write of its own.
        $history = new ChatHistory($store, 'thread');
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));
        (new Summarization($provider, maxTokens: 50, messagesToKeep: 2))
            ->before(new ChatNode(), new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $history, $provider));

        $provider->assertCallCount(1);
        $this->assertStringContainsString('Summary', (string) $history->getMessages()[0]->getContent());
    }

    public function test_the_summary_request_offers_no_tools(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('Question 1'));
        $history->addMessage((new AssistantMessage('Answer 1'))->setUsage(new Usage(40, 10)));
        $history->addMessage(new UserMessage('Question 2'));
        $history->addMessage((new AssistantMessage('Answer 2'))->setUsage(new Usage(80, 20)));
        // A provider shared with the agent still holds the tools of its last inference.
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));
        $provider->setTools([new SearchTool()]);

        (new Summarization($provider, maxTokens: 50, messagesToKeep: 2))
            ->before(new ChatNode(), new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $history, $provider));

        $provider->assertCallCount(1);
        $this->assertSame([], $provider->getRecorded()[0]->tools);
    }

    public function test_the_middleware_hook_can_summarize_with_the_agent_provider(): void
    {
        $provider = new FakeAIProvider(
            (new AssistantMessage('Answer 1'))->setUsage(new Usage(40, 10)),
            new AssistantMessage('Summary'),
            new AssistantMessage('Answer 2'),
        );
        $agent = new class ($provider) extends Agent {
            public function __construct(protected AIProviderInterface $fakeProvider)
            {
                parent::__construct();
            }

            protected function provider(): AIProviderInterface
            {
                return $this->fakeProvider;
            }

            protected function middleware(): array
            {
                return [InferenceNode::class => new Summarization($this->getProvider(), maxTokens: 1, messagesToKeep: 1)];
            }
        };

        $agent->chat(new UserMessage('Question 1'));
        $agent->chat(new UserMessage('Question 2'));

        $provider->assertCallCount(3);
        $this->assertStringContainsString('Summary', (string) $agent->getChatHistory()->getMessages()[0]->getContent());
    }

    protected function conversation(): ChatHistory
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('Question 1'));
        $history->addMessage((new AssistantMessage('Answer 1'))->setUsage(new Usage(40, 10)));
        $history->addMessage(new UserMessage('Question 2'));
        $history->addMessage((new AssistantMessage('Answer 2'))->setUsage(new Usage(80, 20)));

        return $history;
    }

    protected function runBefore(Summarization $middleware, ChatHistory $history, AIProviderInterface $provider, ?Event $event = null): void
    {
        $middleware->before(new ChatNode(), $event ?? new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $history, $provider));
    }

    public function test_events_other_than_inference_are_ignored(): void
    {
        $history = $this->conversation();
        $before = $history->getMessages();
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(
            new Summarization($provider, maxTokens: 1, messagesToKeep: 1),
            $history,
            $provider,
            new ToolCallEvent(new ToolCallMessage(null, [new ToolCall('search', 'call_1')])),
        );

        $provider->assertNothingSent();
        $this->assertSame($before, $history->getMessages());
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_a_non_positive_threshold_disables_summarization(int $maxTokens): void
    {
        $history = $this->conversation();
        $before = $history->getMessages();
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(new Summarization($provider, maxTokens: $maxTokens, messagesToKeep: 1), $history, $provider);

        $provider->assertNothingSent();
        $this->assertSame($before, $history->getMessages());
    }

    public function test_a_conversation_at_the_threshold_is_left_untouched(): void
    {
        $history = $this->conversation();
        $before = $history->getMessages();
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(new Summarization($provider, maxTokens: $history->calculateTotalUsage(), messagesToKeep: 1), $history, $provider);

        $provider->assertNothingSent();
        $this->assertSame($before, $history->getMessages());
    }

    public function test_a_conversation_just_over_the_threshold_is_summarized(): void
    {
        $history = $this->conversation();
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(new Summarization($provider, maxTokens: $history->calculateTotalUsage() - 1, messagesToKeep: 1), $history, $provider);

        $provider->assertCallCount(1);
        $this->assertSame(
            ["## Previous conversation summary:\n\nSummary", 'Answer 2'],
            $this->contents($history->getMessages())
        );
    }

    public function test_a_conversation_no_longer_than_the_kept_messages_is_left_untouched(): void
    {
        $history = $this->conversation();
        $before = $history->getMessages();
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(new Summarization($provider, maxTokens: 1, messagesToKeep: 4), $history, $provider);

        $provider->assertNothingSent();
        $this->assertSame($before, $history->getMessages());
    }

    public function test_a_failed_summary_degrades_to_a_placeholder(): void
    {
        $history = $this->conversation();
        // An empty queue makes the provider throw a ProviderException.
        $provider = new FakeAIProvider();

        $this->runBefore(new Summarization($provider, maxTokens: 1, messagesToKeep: 1), $history, $provider);

        $this->assertSame(
            [
                "## Previous conversation summary:\n\nPrevious conversation contained 3 messages covering various topics.",
                'Answer 2',
            ],
            $this->contents($history->getMessages())
        );
    }

    public function test_the_summary_request_describes_the_conversation_and_its_tool_activity(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('Search for PHP'));
        foreach ($this->toolCallPair() as $message) {
            $history->addMessage($message);
        }
        $history->addMessage(new AssistantMessage('PHP is a language'));
        $history->addMessage(new UserMessage('Thanks'));
        $history->addMessage(new AssistantMessage('You are welcome'));
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(
            new Summarization($provider, maxTokens: 1, messagesToKeep: 1, summaryPrompt: 'Summarize:'),
            $history,
            $provider,
        );

        $record = $provider->getRecorded()[0];
        $this->assertSame('You are a helpful assistant that creates concise, informative summaries of conversations.', $record->systemPrompt?->getContent());
        $this->assertCount(1, $record->messages);
        $this->assertSame(
            "Summarize:\n\n[USER]: Search for PHP\n[ASSISTANT]: Called tools: search\n[USER]: Tool results received\n"
            . "[ASSISTANT]: PHP is a language\n[USER]: Thanks",
            $record->messages[0]->getContent()
        );
    }

    public function test_the_default_prompt_asks_for_a_comprehensive_summary(): void
    {
        $history = $this->conversation();
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(new Summarization($provider, maxTokens: 1, messagesToKeep: 1), $history, $provider);

        $request = (string) $provider->getRecorded()[0]->messages[0]->getContent();
        $this->assertStringStartsWith('Please provide a comprehensive summary of the following conversation.', $request);
        $this->assertStringEndsWith("\n\n[USER]: Question 1\n[ASSISTANT]: Answer 1\n[USER]: Question 2", $request);
    }

    public function test_setters_reconfigure_the_middleware(): void
    {
        $history = $this->conversation();
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));
        $middleware = new Summarization($provider);

        $this->assertSame($middleware, $middleware->setMaxTokens(1));
        $this->assertSame($middleware, $middleware->setMessagesToKeep(1));
        $this->assertSame($middleware, $middleware->setSummaryPrompt('Custom prompt'));
        $this->runBefore($middleware, $history, $provider);

        $this->assertStringStartsWith("Custom prompt\n\n", (string) $provider->getRecorded()[0]->messages[0]->getContent());
        $this->assertCount(2, $history->getMessages());
    }

    public function test_a_dedicated_provider_leaves_the_segment_provider_alone(): void
    {
        $history = $this->conversation();
        $dedicated = new FakeAIProvider(new AssistantMessage('Summary'));
        $segment = new FakeAIProvider();

        $this->runBefore(new Summarization($dedicated, maxTokens: 1, messagesToKeep: 1), $history, $segment);

        $dedicated->assertCallCount(1);
        $segment->assertNothingSent();
    }

    public function test_without_a_dedicated_provider_the_segment_provider_summarizes(): void
    {
        $history = $this->conversation();
        $segment = new FakeAIProvider(new AssistantMessage('Summary'));

        $this->runBefore(new Summarization(maxTokens: 1, messagesToKeep: 1), $history, $segment);

        $segment->assertCallCount(1);
        $this->assertSame("## Previous conversation summary:\n\nSummary", $history->getMessages()[0]->getContent());
    }
}
