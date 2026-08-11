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
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;
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
        return new class () implements PersistenceInterface {
            /** @var array<string, array<string, string>> */
            public array $storage = [];

            public function put(string $partition, string $key, string $value): void
            {
                $this->storage[$partition][$key] = $value;
            }

            public function get(string $partition, string $key): ?string
            {
                return $this->storage[$partition][$key] ?? null;
            }

            public function delete(string $partition): void
            {
                // Keep the records: tests replay and inspect after completion.
            }
        };
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
        $first = Agent::make(runId: 'binding_wake_test', threadId: 'thread-1');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->setChatHistory(new SQLChatHistory($pdo));
        $first->chat(new UserMessage('hello'))->getMessage();

        // Wake: a BLANK process — runId only, another unbound history. The
        // threadId arrives via the adopted ignition context and is bound
        // into the history before any message is touched.
        $second = Agent::make(runId: 'binding_wake_test');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(new SQLChatHistory($pdo));

        $second->run();

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

        $agent = Agent::make(runId: 'thread_ignition_test');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $agent->setPersistence($persistence);
        $agent->setChatHistory(new InMemoryChatHistory('thread-42'));

        $agent->chat(new UserMessage('hello'))->getMessage();

        $record = $persistence->get('thread_ignition_test', '__ignition');
        $this->assertNotNull($record);

        $ignition = (new PhpSerializer())->unserialize($record);
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

        // Blank instance: unbound history, runId only — the threadId arrives
        // via the adopted ignition context and is bound by the framework,
        // never set by the caller.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');
        $second = Agent::make(runId: 'thread_adoption_test');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setChatHistory(new SQLChatHistory($pdo));

        $state = $second->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('thread-42', $second->getChatHistory()->getThreadId());
    }

    public function test_explicit_thread_id_with_an_unbound_history_on_a_fresh_run(): void
    {
        // The binding model's payoff: identity stated once at the front door,
        // never in wiring code — and the run ignites thread-addressable.
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
        // The run ignited addressable by its thread.
        $this->assertSame($agent->getRunId(), $persistence->get('__correlation', 'thread-x'));
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

    public function test_conflicting_concrete_history_throws(): void
    {
        $agent = Agent::make(threadId: 'thread-a');

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Conflicting thread identity');

        $agent->setChatHistory(new InMemoryChatHistory('thread-b'));
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

        $first = Agent::make(runId: 'thread_conflict_test');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->setChatHistory(new InMemoryChatHistory('thread-42'));
        $first->chat(new UserMessage('hello'))->getMessage();

        // A mis-addressed continuation: the caller claims another thread's
        // identity for this runId. The record contradicts it — throw.
        $second = Agent::make(runId: 'thread_conflict_test', threadId: 'thread-other');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Conflicting thread identity');

        $second->run();
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
