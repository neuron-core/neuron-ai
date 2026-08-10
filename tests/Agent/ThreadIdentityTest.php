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
use NeuronAI\Workflow\Executor\Ignition;
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

    public function test_history_resolver_fires_once_on_wake_with_the_ignition_thread_id(): void
    {
        $persistence = $this->retainingPersistence();

        // Ignition: concrete history carries the identity into the ignition record.
        $first = Agent::make(runId: 'resolver_wake_test');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->setChatHistory(new InMemoryChatHistory('thread-1'));
        $first->chat(new UserMessage('hello'))->getMessage();

        // Wake: a BLANK factory — runId only, history resolver wired. The
        // threadId arrives via the adopted ignition context and fires the
        // resolver exactly once.
        $calls = [];
        $second = Agent::make(runId: 'resolver_wake_test');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(function (string $threadId) use (&$calls): ChatHistoryInterface {
            $calls[] = $threadId;
            return new InMemoryChatHistory($threadId);
        });

        $second->run();

        $this->assertSame(['thread-1'], $calls);
        $this->assertSame('thread-1', $second->getChatHistory()->getThreadId());
    }

    public function test_channel_resolver_materializes_from_the_history_identity(): void
    {
        $calls = [];

        $agent = Agent::make();
        $agent->setChatHistory(new InMemoryChatHistory('thread-3'));
        $agent->setChannel(function (string $threadId) use (&$calls): FakeChannel {
            $calls[] = $threadId;
            return new FakeChannel();
        });

        $this->assertSame(['thread-3'], $calls);
    }

    public function test_history_resolver_pending_on_a_fresh_run_fails_loud(): void
    {
        $agent = Agent::make();
        $agent->setChatHistory(fn (string $threadId): ChatHistoryInterface => new InMemoryChatHistory($threadId));

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('A chat history resolver can only be resolved on a wake');

        $agent->getChatHistory();
    }

    public function test_concrete_history_clears_a_pending_resolver(): void
    {
        $calls = [];
        $concrete = new InMemoryChatHistory('thread-4');

        $agent = Agent::make();
        $agent->setChatHistory(function (string $threadId) use (&$calls): ChatHistoryInterface {
            $calls[] = $threadId;
            return new InMemoryChatHistory();
        });
        $agent->setChatHistory($concrete);

        $this->assertSame($concrete, $agent->getChatHistory());
        $this->assertSame([], $calls);
    }

    public function test_resolver_returning_the_wrong_type_fails_loud(): void
    {
        $persistence = $this->retainingPersistence();

        $first = Agent::make(runId: 'wrong_type_test');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->setChatHistory(new InMemoryChatHistory('thread-5'));
        $first->chat(new UserMessage('hello'))->getMessage();

        $second = Agent::make(runId: 'wrong_type_test');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(fn (string $threadId): string => $threadId);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('The chat history resolver must return a');

        $second->run();
    }

    public function test_thread_id_is_recorded_in_the_ignition_context(): void
    {
        $persistence = $this->retainingPersistence();

        $agent = Agent::make(runId: 'thread_ignition_test');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $agent->setPersistence($persistence);
        $agent->setChatHistory(new InMemoryChatHistory('thread-42'));

        $agent->chat(new UserMessage('hello'))->getMessage();

        $record = $persistence->load('thread_ignition_test', '__ignition');
        $this->assertInstanceOf(StepResult::class, $record);

        $ignition = $record->getOutput();
        $this->assertInstanceOf(Ignition::class, $ignition);
        $this->assertSame(['threadId' => 'thread-42'], $ignition->context);
    }

    public function test_blank_instance_adopts_the_thread_id_and_materializes_resolvers(): void
    {
        $persistence = $this->retainingPersistence();

        $first = Agent::make(runId: 'thread_adoption_test');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->setChatHistory(new InMemoryChatHistory('thread-42'));
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
            return new InMemoryChatHistory($threadId);
        });

        $state = $second->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('thread-42', $second->getChatHistory()->getThreadId());
        $this->assertSame(['thread-42'], $calls);
    }
}
