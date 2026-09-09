<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Memory\Stub\InspectableMemory;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PDO;
use PHPUnit\Framework\TestCase;

class ThreadIdentityTest extends TestCase
{
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
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');
        $persistence = $this->retainingPersistence();

        // Ignition: explicit identity, unbound history — the agent binds it.
        $first = Agent::make(threadId: 'thread-1');
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

        $second->run([]);

        $this->assertSame('thread-1', $second->getThreadId());
        $this->assertSame('thread-1', $second->getChatHistory()->getThreadId());
        $this->assertNotEmpty($second->getChatHistory()->getMessages());
    }

    public function test_explicit_identity_binds_an_unbound_history_at_the_setter(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');

        $agent = Agent::make(threadId: 'thread-4');
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
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');
        $second = Agent::make(workflowId: 'thread-42');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(new SQLChatHistory($pdo));

        $state = $second->run([]);

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
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');
        $persistence = new InMemoryPersistence();

        $agent = Agent::make(threadId: 'thread-x');
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
        $agent = Agent::make(threadId: 'thread-default');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');

        $agent->chat(new UserMessage('hello'))->getMessage();

        $this->assertSame('thread-default', $agent->getThreadId());
        $this->assertSame('thread-default', $agent->getChatHistory()->getThreadId());
    }

    public function test_explicit_history_setter_selects_the_thread(): void
    {
        $agent = Agent::make(threadId: 'thread-a');
        $history = new InMemoryChatHistory('thread-b');

        $agent->setChatHistory($history);

        $this->assertSame('thread-b', $agent->getThreadId());
        $this->assertSame($history, $agent->getChatHistory());
    }

    public function test_conflicting_hook_history_throws(): void
    {
        $agent = new class (threadId: 'thread-a') extends Agent {
            protected function chatHistory(): ChatHistoryInterface
            {
                return new InMemoryChatHistory('thread-b');
            }
        };

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Conflicting thread identity');

        $agent->getChatHistory();
    }

    public function test_history_can_be_swapped_after_an_interaction_and_swapped_back(): void
    {
        $first = new InMemoryChatHistory('thread-a');
        $second = new InMemoryChatHistory('thread-b');
        $provider = new FakeAIProvider(
            new AssistantMessage('First reply'),
            new AssistantMessage('Second reply'),
            new AssistantMessage('Welcome back'),
        );
        $memory = new InspectableMemory();
        $persistence = new InMemoryPersistence();
        $agent = Agent::make();
        $agent->setAiProvider($provider)->setInstructions('test')
            ->setChatHistory($first)->setMemory($memory);
        $agent->setPersistence($persistence)->retainCompletionUntilAcknowledged();

        $agent->chat(new UserMessage('First conversation'));
        $firstRunId = $agent->getRunId();
        $firstIgnition = $persistence->get('thread-a', '__ignition');

        $this->assertSame($agent, $agent->setChatHistory($second));
        $this->assertNull($agent->getRunId());
        $this->assertNull($agent->getWorkflowId());
        $agent->chat(new UserMessage('Second conversation'));

        $this->assertSame('thread-b', $agent->getThreadId());
        $this->assertSame('thread-b', $agent->getWorkflowId());
        $this->assertNotSame($firstRunId, $agent->getRunId());
        $this->assertCount(2, $first->getMessages());
        $this->assertCount(2, $second->getMessages());
        $this->assertCount(1, $provider->getRecorded()[1]->messages);
        $this->assertSame('Second conversation', $provider->getRecorded()[1]->messages[0]->getContent());
        $this->assertSame($firstIgnition, $persistence->get('thread-a', '__ignition'));
        $ignition = (new PhpSerializer())->unserialize($persistence->get('thread-b', '__ignition'));
        $this->assertInstanceOf(Ignition::class, $ignition);
        $this->assertSame(['threadId' => 'thread-b'], $ignition->context);
        $this->assertSame([
            ['thread-a', 'First conversation', 'First reply'],
            ['thread-b', 'Second conversation', 'Second reply'],
        ], $memory->remembered);

        $agent->setChatHistory($first);
        $agent->acknowledgeCompletion($firstRunId);
        $agent->chat(new UserMessage('Back to first'));

        $this->assertSame('thread-a', $agent->getWorkflowId());
        $this->assertCount(4, $first->getMessages());
        $this->assertCount(2, $second->getMessages());
        $this->assertCount(3, $provider->getRecorded()[2]->messages);
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

    public function test_anonymous_agent_can_select_a_thread_after_its_first_interaction(): void
    {
        $agent = Agent::make()->setInstructions('test')->setAiProvider(
            new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply')),
        );
        $agent->chat(new UserMessage('Anonymous conversation'));
        $first = $agent->getChatHistory();

        $agent->setChatHistory(new InMemoryChatHistory('thread-b'))->chat(new UserMessage('Named conversation'));

        $this->assertSame('thread-b', $agent->getWorkflowId());
        $this->assertCount(2, $first->getMessages());
        $this->assertCount(2, $agent->getChatHistory()->getMessages());
    }

    public function test_unbound_history_replacement_keeps_the_current_thread_after_execution(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');
        $provider = new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply'));
        $agent = Agent::make(threadId: 'thread-a')->setAiProvider($provider)->setInstructions('test');
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

    public function test_history_swap_during_streaming_is_rejected_without_changing_the_conversation(): void
    {
        $agent = Agent::make(threadId: 'thread-a')->setInstructions('test')->setAiProvider(
            new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply')),
        );
        $stream = $agent->stream(new UserMessage('First conversation'));
        $stream->rewind();
        $first = $agent->getChatHistory();
        $second = new InMemoryChatHistory('thread-b');

        try {
            $agent->setChatHistory($second);
            $this->fail('An active stream must keep its history.');
        } catch (AgentException $exception) {
            $this->assertSame('Cannot replace chat history while the agent is executing.', $exception->getMessage());
        }

        $this->assertSame('thread-a', $agent->getThreadId());
        $this->assertSame($first, $agent->getChatHistory());
        foreach ($stream as $chunk) {
        }

        $agent->setChatHistory($second)->chat(new UserMessage('Second conversation'));

        $this->assertSame('thread-b', $agent->getWorkflowId());
        $this->assertCount(2, $first->getMessages());
        $this->assertCount(2, $second->getMessages());
    }

    public function test_matching_concrete_history_is_fine(): void
    {
        $agent = Agent::make(threadId: 'thread-a');
        $agent->setChatHistory(new InMemoryChatHistory('thread-a'));

        $this->assertSame('thread-a', $agent->getThreadId());
    }

    public function test_conflicting_ignition_identity_on_resume_throws(): void
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
        $second = Agent::make(workflowId: 'thread-42', threadId: 'thread-other');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Misidentified run');

        $second->run([]);
    }

    public function test_anonymous_run_adopts_the_default_history_self_key(): void
    {
        // No explicit identity anywhere: the in-memory default self-keys (its
        // own storage detail) and that key is adopted at first need — the
        // framework itself never fabricates an identity.
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');

        $agent->chat(new UserMessage('hello'))->getMessage();

        $this->assertNotNull($agent->getThreadId());
        $this->assertSame($agent->getChatHistory()->getThreadId(), $agent->getThreadId());
    }

    public function test_thread_id_is_null_while_nothing_declared_an_identity(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');

        $agent = Agent::make();
        $agent->setChatHistory(new SQLChatHistory($pdo));

        // No explicit id, an unbound history: identity is not resolvable —
        // null, not an exception and not a fabricated id.
        $this->assertNull($agent->getThreadId());
    }

    public function test_identity_reading_hook_is_harmless(): void
    {
        // getThreadId() is a pure read, so a hook may consult it freely: on
        // an anonymous run it sees null (the in-memory default self-keys);
        // with an explicit identity it sees the declared thread.
        $anonymous = new class () extends Agent {
            protected function chatHistory(): ChatHistoryInterface
            {
                return new InMemoryChatHistory($this->getThreadId());
            }
        };
        $this->assertNotNull($anonymous->getChatHistory()->getThreadId());

        $declared = new class (threadId: 'thread-read') extends Agent {
            protected function chatHistory(): ChatHistoryInterface
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
        $agent = new class (threadId: 'thread-hook') extends Agent {
            protected function chatHistory(): ChatHistoryInterface
            {
                $pdo = new PDO('sqlite::memory:');
                $pdo->exec('CREATE TABLE chat_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id TEXT, role TEXT, content TEXT, meta TEXT
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
