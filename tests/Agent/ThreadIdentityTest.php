<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PDO;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class ThreadIdentityTest extends TestCase
{
    public function test_construction_and_lazy_stream_creation_leave_identity_unbound(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')));
        $stream = $agent->stream(new UserMessage('Hello'));

        self::assertNull($agent->getThreadId());
        self::assertNull($agent->getWorkflowId());
        self::assertNull($agent->inspect());

        $agent->setThreadId('conversation');
        iterator_to_array($stream);

        self::assertSame('conversation', $stream->getReturn()->getWorkflowId());
        self::assertSame('conversation', $agent->getChatHistory()->getThreadId());
    }

    public function test_unbound_history_access_throws_without_binding_the_agent(): void
    {
        $agent = Agent::make();
        try {
            $agent->getChatHistory();
            self::fail('History access requires a conversation identity.');
        } catch (AgentException $error) {
            self::assertStringContainsString('setThreadId()', $error->getMessage());
        }
        self::assertNull($agent->getThreadId());
    }

    public function test_turns_reuse_the_configured_identity_and_history(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hi Alice'), new AssistantMessage('Alice'));
        $agent = Agent::make()->setAiProvider($provider)->setThreadId('conversation');
        $first = $agent->run(ExecutionRequest::start(
            new AgentStartEvent([new UserMessage('My name is Alice')]),
        ));
        $second = $agent->chat(new UserMessage('What is my name?'));

        self::assertSame('conversation', $agent->getThreadId());
        self::assertSame($first->getWorkflowId(), $second->getWorkflowId());
        self::assertNotSame($first->getRunId(), $second->getRunId());
        self::assertCount(4, $agent->getChatHistory()->getMessages());
        self::assertCount(3, $provider->getRecorded()[1]->messages);

        $this->expectException(WorkflowException::class);
        $agent->setThreadId('another-conversation');
    }

    public function test_bound_agent_resumes_without_constructing_services(): void
    {
        $store = new InMemoryPersistence();
        $first = Agent::make(workflowId: 'conversation')->setPersistence($store)
            ->retainCompletionUntilAcknowledged()
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Saved')))
            ->chat(new UserMessage('Hello'));
        $agent = Agent::make()->setPersistence($store)->setThreadId('conversation');
        $state = $agent->run(ExecutionRequest::resume());

        self::assertSame($first->getRunId(), $state->getRunId());
        self::assertSame('Saved', $state->getMessage()->getContent());
        self::assertSame('conversation', $agent->getThreadId());
    }

    public function test_first_execution_binds_injected_unbound_history(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
        )');
        $history = new SQLChatHistory($pdo);
        $agent = Agent::make()->setChatHistory($history)
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')));
        self::assertNull($history->getThreadId());
        $state = $agent->chat(new UserMessage('Hello'));

        self::assertSame($state->getWorkflowId(), $history->getThreadId());
        self::assertCount(2, $history->getMessages());
        self::assertSame($history, $agent->getChatHistory());
    }

    public function test_identity_setters_share_one_binding_and_reject_rebinding(): void
    {
        $agent = Agent::make();
        self::assertSame($agent, $agent->setThreadId('conversation'));
        self::assertSame($agent, $agent->setWorkflowId('conversation'));
        self::assertSame('conversation', $agent->getThreadId());
        self::assertSame('conversation', $agent->getWorkflowId());

        $this->expectException(WorkflowException::class);
        $agent->setWorkflowId('another-conversation');
    }

    /**
     * A step store that survives run completion, so the ignition record can be
     * inspected after the run and adopted by a later blank instance.
     */
    protected function retainingPersistence(): PersistenceInterface
    {
        return new InMemoryPersistence();
    }

    public function test_unbound_history_is_bound_on_wake_from_the_ignition_record(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
        )');
        $persistence = $this->retainingPersistence();

        // Ignition: explicit identity, unbound history — the agent binds it.
        $first = Agent::make(workflowId: 'thread-1');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->retainCompletionUntilAcknowledged();
        $first->setChatHistory(new SQLChatHistory($pdo));
        $first->chat(new UserMessage('hello'))->getMessage();

        // Wake: a BLANK process — the workflow ID only, another unbound history.
        // The threadId arrives via the adopted ignition context and is bound
        // into the history before any message is touched.
        $second = Agent::make(workflowId: 'thread-1');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(new SQLChatHistory($pdo));

        $second->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());

        $this->assertSame('thread-1', $second->getThreadId());
        $this->assertSame('thread-1', $second->getChatHistory()->getThreadId());
        $this->assertNotEmpty($second->getChatHistory()->getMessages());
    }

    public function test_explicit_identity_binds_an_unbound_history_at_the_setter(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
        )');

        $agent = Agent::make(workflowId: 'thread-4');
        $agent->setChatHistory(new SQLChatHistory($pdo));

        $this->assertSame('thread-4', $agent->getChatHistory()->getThreadId());
    }

    public function test_pre_bound_history_still_declares_identity(): void
    {
        $concrete = new InMemoryChatHistory('thread-4');

        $agent = Agent::make();
        $agent->setChatHistory($concrete);

        $this->assertSame($concrete, $agent->getChatHistory());
        $this->assertSame('thread-4', $agent->getThreadId());
    }

    public function test_thread_id_is_recorded_in_the_ignition_context(): void
    {
        $persistence = $this->retainingPersistence();

        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $agent->setPersistence($persistence);
        $agent->retainCompletionUntilAcknowledged();
        $agent->setChatHistory(new InMemoryChatHistory('thread-42'));

        $agent->chat(new UserMessage('hello'))->getMessage();

        $record = $persistence->get('thread-42', '__ignition');
        $this->assertNotNull($record);

        $ignition = (new PhpSerializer())->unserialize($record);
        $this->assertInstanceOf(Ignition::class, $ignition);
        $this->assertSame(['threadId' => 'thread-42'], $ignition->context);
    }

    public function test_blank_instance_adopts_the_thread_id_and_materializes_resolvers(): void
    {
        $persistence = $this->retainingPersistence();

        $first = Agent::make();
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->retainCompletionUntilAcknowledged();
        $first->setChatHistory(new InMemoryChatHistory('thread-42'));
        $first->chat(new UserMessage('hello'))->getMessage();

        // Blank instance: unbound history, the workflow ID only — the threadId
        // arrives via the adopted ignition context and is bound by the
        // framework, never set by the caller.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
        )');
        $second = Agent::make(workflowId: 'thread-42');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(new SQLChatHistory($pdo));

        $state = $second->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('thread-42', $second->getChatHistory()->getThreadId());
    }

    public function test_explicit_thread_id_with_an_unbound_history_on_a_fresh_run(): void
    {
        // The binding model's payoff: identity stated once at the front door,
        // never in wiring code — and the run ignites findable by its thread.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
        )');
        $persistence = new InMemoryPersistence();

        $agent = Agent::make(workflowId: 'thread-x');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $agent->setPersistence($persistence);
        $agent->setChatHistory(new SQLChatHistory($pdo));

        $agent->chat(new UserMessage('hello'))->getMessage();

        $this->assertSame('thread-x', $agent->getThreadId());
        $this->assertSame('thread-x', $agent->getChatHistory()->getThreadId());
        // The run ignited findable by its thread: the thread IS the
        // workflow ID — and clean completion swept its partition entirely.
        $this->assertSame('thread-x', $agent->getWorkflowId());
        $this->assertNull($persistence->get('thread-x', '__ignition'));
    }

    public function test_explicit_thread_id_keys_the_default_history(): void
    {
        $agent = Agent::make(workflowId: 'thread-default');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');

        $agent->chat(new UserMessage('hello'))->getMessage();

        $this->assertSame('thread-default', $agent->getThreadId());
        $this->assertSame('thread-default', $agent->getChatHistory()->getThreadId());
    }

    public function test_conflicting_history_setter_preserves_the_current_history(): void
    {
        $agent = Agent::make(workflowId: 'thread-a');
        $original = $agent->getChatHistory();
        try {
            $agent->setChatHistory(new InMemoryChatHistory('thread-b'));
            self::fail('A bound Agent cannot switch conversations.');
        } catch (AgentException) {
            self::assertSame('thread-a', $agent->getThreadId());
            self::assertSame($original, $agent->getChatHistory());
        }
    }

    public function test_conflicting_hook_history_throws(): void
    {
        $agent = new class (workflowId: 'thread-a') extends Agent {
            protected function chatHistory(string $threadId): ChatHistoryInterface
            {
                return new InMemoryChatHistory('thread-b');
            }
        };

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Chat history conflicts');

        $agent->getChatHistory();
    }

    public function test_conflicting_history_preserves_a_completed_run(): void
    {
        $agent = Agent::make(workflowId: 'thread-a')->retainCompletionUntilAcknowledged();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Reply')));
        $state = $agent->chat(new UserMessage('Hello'));
        $ignition = $agent->getPersistence()->get('thread-a', '__ignition');

        try {
            $agent->setChatHistory(new InMemoryChatHistory('thread-b'));
            self::fail('A completed run does not release the instance identity.');
        } catch (AgentException) {
            self::assertSame($state->getRunId(), $agent->inspect()?->runId);
            self::assertSame($ignition, $agent->getPersistence()->get('thread-a', '__ignition'));
            self::assertCount(2, $agent->getChatHistory()->getMessages());
            self::assertNull($agent->getPersistence()->get('thread-b', '__control'));
        }
    }

    public function test_same_thread_history_replacement_updates_composed_nodes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply'));
        $first = new InMemoryChatHistory('thread-a');
        $agent = Agent::make()->setAiProvider($provider)->setInstructions('test')->setChatHistory($first);
        $agent->chat(new UserMessage('First conversation'));

        $second = new InMemoryChatHistory('thread-a');
        $agent->setChatHistory($second)->chat(new UserMessage('Second conversation'));

        $this->assertCount(2, $first->getMessages());
        $this->assertCount(2, $second->getMessages());
        $this->assertCount(1, $provider->getRecorded()[1]->messages);
    }

    public function test_generated_identity_cannot_be_replaced_after_execution(): void
    {
        $agent = Agent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage('Reply')));
        $state = $agent->chat(new UserMessage('Hello'));

        try {
            $agent->setThreadId('another-thread');
            self::fail('Generated identities remain bound after execution.');
        } catch (WorkflowException) {
            self::assertSame($state->getWorkflowId(), $agent->getThreadId());
            self::assertCount(2, $agent->getChatHistory()->getMessages());
        }
    }

    public function test_unbound_history_replacement_keeps_the_current_thread_after_execution(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
        )');
        $provider = new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply'));
        $agent = Agent::make(workflowId: 'thread-a')->setAiProvider($provider)->setInstructions('test');
        $agent->chat(new UserMessage('First conversation'));
        $first = $agent->getChatHistory();

        $replacement = new SQLChatHistory($pdo);
        $agent->setChatHistory($replacement)->chat(new UserMessage('Second conversation'));

        $this->assertSame('thread-a', $replacement->getThreadId());
        $this->assertSame('thread-a', $agent->getWorkflowId());
        $this->assertCount(2, $first->getMessages());
        $this->assertCount(2, $replacement->getMessages());
        $this->assertCount(1, $provider->getRecorded()[1]->messages);
    }

    public function test_conflicting_history_during_streaming_does_not_redirect_the_run(): void
    {
        $agent = Agent::make(workflowId: 'thread-a')->setAiProvider(
            new FakeAIProvider(new AssistantMessage('Reply')),
        );
        $stream = $agent->stream(new UserMessage('Hello'));
        $stream->rewind();
        $original = $agent->getChatHistory();
        $other = new InMemoryChatHistory('thread-b');

        try {
            $agent->setChatHistory($other);
            self::fail('An active Agent cannot switch conversations.');
        } catch (AgentException) {
            self::assertSame($original, $agent->getChatHistory());
        }
        iterator_to_array($stream);

        self::assertSame('thread-a', $stream->getReturn()->getWorkflowId());
        self::assertSame('Reply', $stream->getReturn()->getMessage()->getContent());
        self::assertCount(2, $original->getMessages());
        self::assertSame([], $other->getMessages());
    }

    public function test_matching_concrete_history_is_fine(): void
    {
        $agent = Agent::make(workflowId: 'thread-a');
        $agent->setChatHistory(new InMemoryChatHistory('thread-a'));

        $this->assertSame('thread-a', $agent->getThreadId());
    }

    public function test_retained_run_identity_cannot_be_changed(): void
    {
        $persistence = $this->retainingPersistence();

        $first = Agent::make();
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->retainCompletionUntilAcknowledged();
        $first->setChatHistory(new InMemoryChatHistory('thread-42'));
        $first->chat(new UserMessage('hello'))->getMessage();

        // A misidentified continuation: the caller claims another thread's
        // identity for this workflow ID. The engine refuses the contradiction
        // before any record is touched.
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Chat history conflicts');
        $first->setThreadId('thread-other');
    }

    public function test_generated_identity_and_default_history_are_retained_across_turns(): void
    {
        $agent = Agent::make()->setAiProvider(
            new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two')),
        );
        $first = $agent->chat(new UserMessage('First'));
        $history = $agent->getChatHistory();
        $second = $agent->chat(new UserMessage('Second'));

        self::assertNotNull($agent->getThreadId());
        self::assertSame($first->getWorkflowId(), $second->getWorkflowId());
        self::assertSame($first->getWorkflowId(), $agent->getThreadId());
        self::assertNotSame($first->getRunId(), $second->getRunId());
        self::assertSame($history, $agent->getChatHistory());
        self::assertSame($agent->getThreadId(), $history->getThreadId());
        self::assertCount(4, $history->getMessages());
    }

    public function test_injected_unbound_history_receives_identity_when_the_agent_is_bound(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
        )');

        $agent = Agent::make();
        $history = new SQLChatHistory($pdo);
        $agent->setChatHistory($history);
        self::assertNull($agent->getThreadId());
        self::assertNull($history->getThreadId());

        $agent->setWorkflowId('thread-later');
        self::assertSame('thread-later', $history->getThreadId());
        self::assertSame($history, $agent->getChatHistory());
    }

    public function test_identity_reading_hook_is_harmless(): void
    {
        // History hooks see the identity established before resource resolution.
        $anonymous = new class () extends Agent {
            protected function chatHistory(string $threadId): ChatHistoryInterface
            {
                return new InMemoryChatHistory($this->getThreadId());
            }
        };
        $anonymous->setAiProvider(new FakeAIProvider(new AssistantMessage('Reply')))
            ->chat(new UserMessage('Hello'));
        $this->assertSame($anonymous->getThreadId(), $anonymous->getChatHistory()->getThreadId());

        $declared = new class (workflowId: 'thread-read') extends Agent {
            protected function chatHistory(string $threadId): ChatHistoryInterface
            {
                return new InMemoryChatHistory($this->getThreadId());
            }
        };
        $this->assertSame('thread-read', $declared->getChatHistory()->getThreadId());
    }

    public function test_identity_free_hook_history_is_bound_by_the_framework(): void
    {
        // The recommended pattern: the hook constructs the history WITHOUT
        // identity; the framework binds the resolved thread into it.
        $agent = new class (workflowId: 'thread-hook') extends Agent {
            protected function chatHistory(string $threadId): ChatHistoryInterface
            {
                $pdo = new PDO('sqlite::memory:');
                $pdo->exec('CREATE TABLE chat_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id TEXT, role TEXT, content TEXT, meta TEXT, archived_at TEXT
                )');

                return new SQLChatHistory($pdo);
            }
        };
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');

        $agent->chat(new UserMessage('hello'))->getMessage();

        $this->assertSame('thread-hook', $agent->getChatHistory()->getThreadId());
        $this->assertSame('thread-hook', $agent->getThreadId());
    }
}
