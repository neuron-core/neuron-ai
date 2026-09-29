<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;
use function serialize;

class ThreadIdentityTest extends TestCase
{
    /** @return iterable<string, array{Closure(Agent): void}> */
    public static function verbs(): iterable
    {
        yield 'chat' => [static function (Agent $agent): void {
            $agent->chat(new UserMessage('Hello'));
        }];
        yield 'stream, before iteration' => [static function (Agent $agent): void {
            $agent->stream(new UserMessage('Hello'));
        }];
        yield 'structured' => [static function (Agent $agent): void {
            $agent->structured(new UserMessage('Hello'), User::class);
        }];
        yield 'run' => [static function (Agent $agent): void {
            $agent->run();
        }];
        yield 'inspect' => [static function (Agent $agent): void {
            $agent->inspect();
        }];
        yield 'submitInputs' => [static function (Agent $agent): void {
            $agent->submitInputs([]);
        }];
        yield 'submitApprovalDecisions' => [static function (Agent $agent): void {
            $agent->submitApprovalDecisions([]);
        }];
        yield 'submitToolResults' => [static function (Agent $agent): void {
            $agent->submitToolResults([]);
        }];
        yield 'pendingApprovals' => [static function (Agent $agent): void {
            $agent->pendingApprovals();
        }];
        yield 'getChatHistory' => [static function (Agent $agent): void {
            $agent->getChatHistory();
        }];
        yield 'resetConversation' => [static function (Agent $agent): void {
            $agent->resetConversation();
        }];
        yield 'abandon' => [static function (Agent $agent): void {
            $agent->abandon();
        }];
    }

    /**
     * A shared, unbound Agent must never make up a thread: one it kept would
     * carry one user's conversation into the next user's requests.
     *
     * @param Closure(Agent): void $verb
     */
    #[DataProvider('verbs')]
    public function test_unbound_agent_refuses_the_call_without_binding_or_writing(Closure $verb): void
    {
        $persistence = new InMemoryPersistence();
        $messages = new InMemoryMessageStore();
        $agent = Agent::make()->setPersistence($persistence)->setMessageStore($messages)
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')));
        $before = [serialize($persistence), serialize($messages)];

        try {
            $verb($agent);
            self::fail('An unbound agent has no thread to serve the call.');
        } catch (AgentException $error) {
            self::assertSame('This agent has no thread ID: bind one with setThreadId() first.', $error->getMessage());
        }

        self::assertNull($agent->getThreadId());
        self::assertNull($agent->getWorkflowId());
        self::assertSame($before, [serialize($persistence), serialize($messages)]);

        $state = $agent->setThreadId('thread_1')->chat(new UserMessage('Hello'));

        self::assertSame('thread_1', $state->getWorkflowId());
        self::assertCount(2, $messages->loadActive('thread_1'));
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

    public function test_a_blank_instance_resumes_the_thread_from_the_shared_store(): void
    {
        $messages = new SqliteMessageStore();
        $persistence = $this->retainingPersistence();

        $first = Agent::make(workflowId: 'thread-1');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->retainCompletionUntilAcknowledged();
        $first->setMessageStore($messages);
        $first->chat(new UserMessage('hello'))->getMessage();

        // Wake: a blank process with the workflow ID and the shared store only.
        $second = Agent::make(workflowId: 'thread-1');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setMessageStore($messages);

        $second->run(ExecutionRequest::resume());

        $this->assertSame('thread-1', $second->getThreadId());
        $this->assertSame('thread-1', $second->getChatHistory()->getThreadId());
        $this->assertCount(2, $second->getChatHistory()->getMessages());
    }

    public function test_blank_instance_adopts_the_thread_id_and_materializes_resolvers(): void
    {
        $persistence = $this->retainingPersistence();

        $first = Agent::make(workflowId: 'thread-42');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($persistence);
        $first->retainCompletionUntilAcknowledged();
        $first->chat(new UserMessage('hello'))->getMessage();

        $second = Agent::make(workflowId: 'thread-42');
        $second->setAiProvider(new FakeAIProvider());
        $second->setInstructions('test');
        $second->setPersistence($persistence);
        $second->setMessageStore(new SqliteMessageStore());

        $state = $second->run(ExecutionRequest::resume());

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('thread-42', $second->getChatHistory()->getThreadId());
    }

    public function test_explicit_thread_id_keys_the_configured_store_on_a_fresh_run(): void
    {
        $messages = new SqliteMessageStore();
        $persistence = new InMemoryPersistence();

        $agent = Agent::make(workflowId: 'thread-x');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $agent->setPersistence($persistence);
        $agent->setMessageStore($messages);

        $agent->chat(new UserMessage('hello'))->getMessage();

        $this->assertSame('thread-x', $agent->getThreadId());
        $this->assertCount(2, $messages->loadActive('thread-x'));
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

    public function test_store_replacement_applies_to_the_next_turn_of_the_same_thread(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply'));
        $first = new InMemoryMessageStore();
        $agent = Agent::make(workflowId: 'thread-a')->setAiProvider($provider)->setInstructions('test')
            ->setMessageStore($first);
        $agent->chat(new UserMessage('First conversation'));

        $second = new InMemoryMessageStore();
        $agent->setMessageStore($second)->chat(new UserMessage('Second conversation'));

        $this->assertSame('thread-a', $agent->getWorkflowId());
        $this->assertCount(2, $first->loadActive('thread-a'));
        $this->assertCount(2, $second->loadActive('thread-a'));
        $this->assertCount(1, $provider->getRecorded()[1]->messages);
    }

    public function test_bound_identity_cannot_be_replaced_after_execution(): void
    {
        $agent = Agent::make()->setThreadId('thread_1')->setAiProvider(new FakeAIProvider(new AssistantMessage('Reply')));
        $state = $agent->chat(new UserMessage('Hello'));

        try {
            $agent->setThreadId('another-thread');
            self::fail('Identities remain bound after execution.');
        } catch (WorkflowException) {
            self::assertSame($state->getWorkflowId(), $agent->getThreadId());
            self::assertCount(2, $agent->getChatHistory()->getMessages());
        }
    }

    public function test_store_replacement_during_streaming_does_not_redirect_the_segment(): void
    {
        $original = new InMemoryMessageStore();
        $agent = Agent::make(workflowId: 'thread-a')->setMessageStore($original)->setAiProvider(
            new FakeAIProvider(new AssistantMessage('Reply')),
        );
        $stream = $agent->stream(new UserMessage('Hello'));
        $stream->rewind();

        $other = new InMemoryMessageStore();
        $agent->setMessageStore($other);
        iterator_to_array($stream);

        self::assertSame('thread-a', $stream->getReturn()->getWorkflowId());
        self::assertSame('Reply', $stream->getReturn()->getMessage()->getContent());
        self::assertCount(2, $original->loadActive('thread-a'));
        self::assertSame([], $other->loadAll('thread-a'));
    }

    public function test_a_misidentified_continuation_is_refused(): void
    {
        $first = Agent::make(workflowId: 'thread-42');
        $first->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');
        $first->setPersistence($this->retainingPersistence());
        $first->retainCompletionUntilAcknowledged();
        $first->chat(new UserMessage('hello'))->getMessage();

        // The caller claims another thread's identity for this instance. The
        // engine refuses the contradiction before any record is touched.
        $this->expectException(WorkflowException::class);
        $first->setThreadId('thread-other');
    }

    public function test_every_access_opens_a_fresh_view_of_the_conversation(): void
    {
        $agent = Agent::make(workflowId: 'thread-a');
        $view = $agent->getChatHistory();

        $view->addMessage(new UserMessage('Written through the first view'));

        self::assertNotSame($view, $agent->getChatHistory());
        self::assertCount(1, $agent->getChatHistory()->getMessages());
    }

    public function test_an_instance_continuing_after_another_worker_sees_the_whole_thread(): void
    {
        // Two long-lived workers share one store and take turns on one thread.
        $messages = new SqliteMessageStore();
        $providerA = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Three'));
        $workerA = Agent::make(workflowId: 'thread-a')->setMessageStore($messages)->setAiProvider($providerA);
        $workerB = Agent::make(workflowId: 'thread-a')->setMessageStore($messages)
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Two')));

        $workerA->chat(new UserMessage('First'));
        $workerB->chat(new UserMessage('Second'));
        $workerA->chat(new UserMessage('Third'));

        // The third turn reads the thread as the second worker left it.
        self::assertCount(5, $providerA->getRecorded()[1]->messages);
        self::assertSame(
            ['First', 'One', 'Second', 'Two', 'Third', 'Three'],
            array_map(fn (Message $message): ?string => $message->getContent(), $messages->loadActive('thread-a'))
        );
    }

    public function test_message_store_hook_serves_the_agent_thread(): void
    {
        $agent = new class (workflowId: 'thread-hook') extends Agent {
            public ?MessageStoreInterface $store = null;

            protected function messageStore(): MessageStoreInterface
            {
                return $this->store = new SqliteMessageStore();
            }
        };
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');

        $agent->chat(new UserMessage('hello'))->getMessage();

        $this->assertSame('thread-hook', $agent->getChatHistory()->getThreadId());
        $this->assertCount(2, $agent->store->loadActive('thread-hook'));
    }
}
