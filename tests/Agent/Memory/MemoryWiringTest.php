<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Memory\MemoryAwareChatHistory;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MemoryWiringTest extends TestCase
{
    public function test_decorator_forgets_memory_before_clearing_history(): void
    {
        $memory = new RecordingMemory();
        $inner = new InMemoryChatHistory('thread-1');
        $history = new MemoryAwareChatHistory($inner, $memory);

        $this->assertSame($history, $history->addMessage(new UserMessage('Hello')));
        $history->addMessage(new AssistantMessage('Hi'));

        $this->assertSame($inner->getMessages(), $history->getMessages());
        $this->assertSame($inner->jsonSerialize(), $history->jsonSerialize());

        $this->assertSame($history, $history->flushAll());
        $this->assertSame(['thread-1'], $memory->forgottenThreadIds);
        $this->assertSame([], $inner->getMessages());
    }

    public function test_memory_failure_preserves_chat_history(): void
    {
        $memory = new class () implements MemoryInterface {
            public function forget(string $threadId): void
            {
                throw new RuntimeException('Memory store unavailable.');
            }
        };
        $inner = new InMemoryChatHistory('thread-1');
        $inner->addMessage(new UserMessage('Hello'));
        $inner->addMessage(new AssistantMessage('Hi'));
        $history = new MemoryAwareChatHistory($inner, $memory);

        try {
            $history->flushAll();
            $this->fail('The memory failure should be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Memory store unavailable.', $exception->getMessage());
        }

        $this->assertCount(2, $inner->getMessages());
    }

    public function test_agent_wraps_the_configured_history_once(): void
    {
        $memory = new RecordingMemory();
        $inner = new InMemoryChatHistory('thread-1');
        $agent = Agent::make()
            ->setMemory($memory)
            ->setChatHistory($inner);

        $history = $agent->getChatHistory();

        $this->assertInstanceOf(MemoryAwareChatHistory::class, $history);
        $this->assertSame($history, $agent->getChatHistory());
        $this->assertSame('thread-1', $history->getThreadId());
        $this->assertSame('thread-1', $agent->getThreadId());
        $this->assertSame($memory, $agent->getMemory());
    }

    public function test_configuration_order_does_not_change_the_result(): void
    {
        $memory = new RecordingMemory();
        $inner = new InMemoryChatHistory('thread-1');
        $agent = Agent::make()
            ->setChatHistory($inner)
            ->setMemory($memory);

        $this->assertInstanceOf(MemoryAwareChatHistory::class, $agent->getChatHistory());
        $this->assertSame('thread-1', $agent->getChatHistory()->getThreadId());
    }

    public function test_replacing_history_before_composition_rebuilds_the_decorator(): void
    {
        $memory = new RecordingMemory();
        $agent = Agent::make()->setMemory($memory);
        $first = $agent->getChatHistory();

        $inner = new InMemoryChatHistory($agent->getThreadId());
        $agent->setChatHistory($inner);
        $second = $agent->getChatHistory();

        $this->assertNotSame($first, $second);
        $this->assertInstanceOf(MemoryAwareChatHistory::class, $second);

        $second->addMessage(new UserMessage('Hello'));
        $this->assertCount(1, $inner->getMessages());
    }

    public function test_memory_hook_is_resolved_lazily(): void
    {
        $memory = new RecordingMemory();
        $agent = new class ($memory) extends Agent {
            public function __construct(protected MemoryInterface $defaultMemory)
            {
                parent::__construct(threadId: 'thread-hook');
            }

            protected function memory(): MemoryInterface
            {
                return $this->defaultMemory;
            }
        };

        $this->assertInstanceOf(MemoryAwareChatHistory::class, $agent->getChatHistory());
        $this->assertSame($memory, $agent->getMemory());
    }

    public function test_agent_without_memory_returns_the_original_history(): void
    {
        $inner = new InMemoryChatHistory('thread-1');
        $agent = Agent::make()->setChatHistory($inner);

        $this->assertSame($inner, $agent->getChatHistory());
        $this->assertNull($agent->getMemory());
    }

    public function test_memory_cannot_change_after_graph_composition(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')));
        $agent->bootstrap();

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('before the Agent starts executing');

        $agent->setMemory(new RecordingMemory());
    }
}

class RecordingMemory implements MemoryInterface
{
    /** @var string[] */
    public array $forgottenThreadIds = [];

    public function forget(string $threadId): void
    {
        $this->forgottenThreadIds[] = $threadId;
    }
}
