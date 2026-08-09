<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PHPUnit\Framework\TestCase;

class ThreadIdentityTest extends TestCase
{
    /**
     * A step store that survives run completion, so the ignition record can be
     * inspected after the run and adopted by a later blank instance.
     */
    protected function retainingPersistence(): PersistenceInterface
    {
        return new class () implements PersistenceInterface {
            /** @var array<string, array<string, StepResult>> */
            public array $storage = [];

            public function save(string $runId, string $stepId, StepResult $result): void
            {
                $this->storage[$runId][$stepId] = $result;
            }

            public function load(string $runId, string $stepId): ?StepResult
            {
                return $this->storage[$runId][$stepId] ?? null;
            }

            public function delete(string $runId): void
            {
                // Keep the steps: tests replay and inspect after completion.
            }
        };
    }

    public function test_resolver_then_thread_id_materializes_exactly_once(): void
    {
        $calls = [];
        $agent = Agent::make();
        $agent->setChatHistory(function (string $threadId) use (&$calls): ChatHistoryInterface {
            $calls[] = $threadId;
            return new InMemoryChatHistory();
        });

        $agent->setThreadId('thread-1');
        $agent->setThreadId('thread-1');
        $agent->getChatHistory();

        $this->assertSame(['thread-1'], $calls);
    }

    public function test_thread_id_then_resolver_materializes_exactly_once(): void
    {
        $calls = [];
        $agent = Agent::make();
        $agent->setThreadId('thread-2');
        $agent->setChatHistory(function (string $threadId) use (&$calls): ChatHistoryInterface {
            $calls[] = $threadId;
            return new InMemoryChatHistory();
        });

        $agent->getChatHistory();

        $this->assertSame(['thread-2'], $calls);
    }

    public function test_channel_resolver_materializes_with_the_thread_id(): void
    {
        $calls = [];
        $channel = new FakeChannel();

        $agent = Agent::make();
        $agent->setChannel(function (string $threadId) use (&$calls, $channel): FakeChannel {
            $calls[] = $threadId;
            return $channel;
        });
        $agent->setThreadId('thread-3');

        $this->assertSame(['thread-3'], $calls);
    }

    public function test_chat_history_resolver_without_thread_id_fails_loudly(): void
    {
        $agent = Agent::make();
        $agent->setChatHistory(fn (string $threadId): ChatHistoryInterface => new InMemoryChatHistory());

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('A chat history resolver is wired but no threadId is set');

        $agent->getChatHistory();
    }

    public function test_channel_resolver_without_thread_id_fails_loudly_at_bootstrap(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $agent->setChannel(fn (string $threadId): FakeChannel => new FakeChannel());

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('A channel resolver is wired but no threadId is set');

        $agent->run();
    }

    public function test_concrete_instance_clears_a_pending_resolver(): void
    {
        $calls = [];
        $concrete = new InMemoryChatHistory();

        $agent = Agent::make();
        $agent->setChatHistory(function (string $threadId) use (&$calls): ChatHistoryInterface {
            $calls[] = $threadId;
            return new InMemoryChatHistory();
        });
        $agent->setChatHistory($concrete);
        $agent->setThreadId('thread-4');

        $this->assertSame($concrete, $agent->getChatHistory());
        $this->assertSame([], $calls);
    }

    public function test_resolver_returning_the_wrong_type_fails_loudly(): void
    {
        $agent = Agent::make();
        $agent->setChatHistory(fn (string $threadId): string => $threadId);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('The chat history resolver must return a');

        $agent->setThreadId('thread-5');
    }

    public function test_thread_id_is_recorded_in_the_ignition_context(): void
    {
        $persistence = $this->retainingPersistence();

        $agent = Agent::make(runId: 'thread_ignition_test');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $agent->setPersistence($persistence);
        $agent->setThreadId('thread-42');

        $agent->chat(new UserMessage('hello'))->getMessage();

        $record = $persistence->load('thread_ignition_test', '__ignition');
        $this->assertInstanceOf(StepResult::class, $record);
        $this->assertSame(['threadId' => 'thread-42'], $record->getOutput()['context']);
    }

    public function test_blank_instance_adopts_the_thread_id_and_materializes_resolvers(): void
    {
        $persistence = $this->retainingPersistence();

        $first = Agent::make(runId: 'thread_adoption_test');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->setThreadId('thread-42');
        $first->chat(new UserMessage('hello'))->getMessage();

        // Blank instance: resolver wired, runId only — the threadId arrives
        // via the adopted ignition context, never set by the caller.
        $calls = [];
        $second = Agent::make(runId: 'thread_adoption_test');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(function (string $threadId) use (&$calls): ChatHistoryInterface {
            $calls[] = $threadId;
            return new InMemoryChatHistory();
        });

        $state = $second->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('thread-42', $second->getThreadId());
        $this->assertSame(['thread-42'], $calls);
    }
}
