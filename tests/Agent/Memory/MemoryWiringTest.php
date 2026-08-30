<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MemoryWiringTest extends TestCase
{
    public function test_configured_history_remains_the_attached_instance(): void
    {
        $memory = new RecordingMemory();
        $history = new InMemoryChatHistory('thread-1');
        $agent = Agent::make()
            ->setMemory($memory)
            ->setChatHistory($history);

        $this->assertSame($history, $agent->getChatHistory());
        $this->assertSame('thread-1', $agent->getThreadId());
        $this->assertSame($memory, $agent->getMemory());
    }

    public function test_configuration_order_does_not_change_component_identity(): void
    {
        $memory = new RecordingMemory();
        $history = new InMemoryChatHistory('thread-1');
        $agent = Agent::make()
            ->setChatHistory($history)
            ->setMemory($memory);

        $this->assertSame($history, $agent->getChatHistory());
        $this->assertSame($memory, $agent->getMemory());
    }

    public function test_replacing_history_returns_the_replacement_directly(): void
    {
        $agent = Agent::make()->setMemory(new RecordingMemory());
        $agent->getChatHistory();

        $replacement = new InMemoryChatHistory($agent->getThreadId());
        $agent->setChatHistory($replacement);

        $this->assertSame($replacement, $agent->getChatHistory());
    }

    public function test_memory_hook_is_resolved_lazily_and_cached(): void
    {
        $memory = new RecordingMemory();
        $agent = new class ($memory) extends Agent {
            public int $memoryCalls = 0;

            public function __construct(protected MemoryInterface $defaultMemory)
            {
                parent::__construct(threadId: 'thread-hook');
            }

            protected function memory(): MemoryInterface
            {
                $this->memoryCalls++;

                return $this->defaultMemory;
            }
        };

        $this->assertSame($memory, $agent->getMemory());
        $this->assertSame($memory, $agent->getMemory());
        $this->assertSame(1, $agent->memoryCalls);
    }

    public function test_agent_without_memory_returns_the_original_history(): void
    {
        $history = new InMemoryChatHistory('thread-1');
        $agent = Agent::make()->setChatHistory($history);

        $this->assertSame($history, $agent->getChatHistory());
        $this->assertNull($agent->getMemory());
    }

    public function test_flushing_chat_history_does_not_forget_long_term_memory(): void
    {
        $memory = new RecordingMemory();
        $history = $this->conversationHistory();
        $agent = Agent::make()
            ->setChatHistory($history)
            ->setMemory($memory);

        $agent->getChatHistory()->flushAll();

        $this->assertSame([], $memory->forgottenThreadIds);
        $this->assertSame([], $history->getMessages());
    }

    public function test_reset_conversation_forgets_memory_and_clears_history(): void
    {
        $memory = new RecordingMemory();
        $history = $this->conversationHistory();
        $agent = Agent::make()
            ->setChatHistory($history)
            ->setMemory($memory);

        $this->assertSame($agent, $agent->resetConversation());
        $this->assertSame(['thread-1'], $memory->forgottenThreadIds);
        $this->assertSame([], $history->getMessages());
    }

    public function test_memory_failure_during_reset_preserves_chat_history(): void
    {
        $memory = new class () implements MemoryInterface {
            public function recall(string $query): array
            {
                return [];
            }

            public function remember(string $threadId, string $user, string $assistant): void
            {
            }

            public function forget(string $threadId): void
            {
                throw new RuntimeException('Memory store unavailable.');
            }
        };
        $history = $this->conversationHistory();
        $agent = Agent::make()
            ->setChatHistory($history)
            ->setMemory($memory);

        try {
            $agent->resetConversation();
            $this->fail('The memory failure should be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Memory store unavailable.', $exception->getMessage());
        }

        $this->assertCount(2, $history->getMessages());
    }

    public function test_reset_conversation_without_memory_clears_history(): void
    {
        $history = $this->conversationHistory();
        $agent = Agent::make()->setChatHistory($history);

        $agent->resetConversation();

        $this->assertSame([], $history->getMessages());
    }

    protected function conversationHistory(): InMemoryChatHistory
    {
        $history = new InMemoryChatHistory('thread-1');
        $history->addMessage(new UserMessage('Hello'));
        $history->addMessage(new AssistantMessage('Hi'));

        return $history;
    }
}

class RecordingMemory implements MemoryInterface
{
    /** @var string[] */
    public array $forgottenThreadIds = [];

    public function recall(string $query): array
    {
        return [];
    }

    public function remember(string $threadId, string $user, string $assistant): void
    {
    }

    public function forget(string $threadId): void
    {
        $this->forgottenThreadIds[] = $threadId;
    }
}
