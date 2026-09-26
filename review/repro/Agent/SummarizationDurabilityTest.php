<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;

class SummarizationDurabilityTest extends TestCase
{
    /**
     * @return Message[]
     */
    protected function conversation(): array
    {
        return [
            new UserMessage('Question 1'),
            (new AssistantMessage('Answer 1'))->setUsage(new Usage(40, 10)),
            new UserMessage('Question 2'),
            (new AssistantMessage('Answer 2'))->setUsage(new Usage(80, 20)),
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

    public function test_a_store_failure_during_the_rewrite_keeps_the_original_thread(): void
    {
        $store = new class () extends InMemoryMessageStore {
            public bool $failAppends = false;

            public function append(string $threadId, Message $message): void
            {
                if ($this->failAppends) {
                    throw new RuntimeException('store unavailable');
                }
                parent::append($threadId, $message);
            }

            public function clear(string $threadId): void
            {
                parent::clear($threadId);
                // The connection drops right after the destructive clear.
                $this->failAppends = true;
            }
        };

        $history = new ChatHistory($store, 'thread');
        foreach ($this->conversation() as $message) {
            $history->addMessage($message);
        }
        $provider = new FakeAIProvider(new AssistantMessage('Summary'));

        try {
            (new Summarization($provider, maxTokens: 1, messagesToKeep: 1))
                ->before(new ChatNode(), new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $history, $provider));
            $this->fail('The store failure should propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('store unavailable', $exception->getMessage());
        }

        $this->assertSame(
            ['Question 1', 'Answer 1', 'Question 2', 'Answer 2'],
            $this->contents($store->loadAll('thread'))
        );
    }

    public function test_replaying_the_step_does_not_summarize_an_already_summarized_history_again(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        foreach ($this->conversation() as $message) {
            $history->addMessage($message);
        }
        $provider = new FakeAIProvider(new AssistantMessage('Summary'), new AssistantMessage('Summary of summary'));
        $middleware = new Summarization($provider, maxTokens: 99, messagesToKeep: 1);

        $middleware->before(new ChatNode(), new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $history, $provider));
        $afterFirstRun = $this->contents($history->getMessages());

        // The inference crashed after the rewrite: recovery re-runs the same step's middleware.
        $replayedHistory = new ChatHistory($store, 'thread');
        $middleware->before(new ChatNode(), new AIInferenceEvent(), new AgentState(), AgentResourcesFactory::make([], $replayedHistory, $provider));

        $provider->assertCallCount(1);
        $this->assertSame(["## Previous conversation summary:\n\nSummary", 'Answer 2'], $afterFirstRun);
        $this->assertSame($afterFirstRun, $this->contents($replayedHistory->getMessages()));
    }

    public function test_a_tool_loop_summarizes_the_conversation_once(): void
    {
        $call = new ToolCall('search', 'call_1', ['query' => 'PHP']);
        $agentProvider = new FakeAIProvider(
            (new AssistantMessage('Answer 1'))->setUsage(new Usage(80, 20)),
            (new ToolCallMessage(null, [$call]))->setUsage(new Usage(30, 5)),
            (new AssistantMessage('Answer 2'))->setUsage(new Usage(40, 5)),
        );
        $summarizer = new FakeAIProvider(new AssistantMessage('Summary'), new AssistantMessage('Summary of summary'));

        $agent = Agent::make()
            ->setAiProvider($agentProvider)
            ->setTools([new SearchTool()])
            ->addMiddleware(InferenceNode::class, new Summarization($summarizer, maxTokens: 90, messagesToKeep: 1));

        $agent->chat(new UserMessage('Question 1'));
        $agent->chat(new UserMessage('Question 2'));

        $agentProvider->assertCallCount(3);
        $summarizer->assertCallCount(1);
    }
}
