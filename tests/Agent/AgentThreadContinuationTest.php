<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/**
 * Thread-first continuation: the Agent declares its threadId as the run's
 * workflow ID, so the run's durable records live under the thread itself. The
 * approve/deny endpoint needs only the thread — no other handle is stored
 * anywhere by the application.
 */
class AgentThreadContinuationTest extends TestCase
{
    protected function makeSuspendedRun(
        string $threadId,
        MessageStoreInterface $messages,
        PersistenceInterface $persistence,
        FakeAIProvider $provider,
        SearchTool $searchTool,
    ): Agent {
        $agent = Agent::make(workflowId: $threadId);
        $agent->setMessageStore($messages);
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setPersistence($persistence);

        $state = $agent->chat(new UserMessage('Search for PHP frameworks'));

        $this->assertTrue($state->isInterrupted());

        return $agent;
    }

    protected function makeProvider(SearchTool $searchTool): FakeAIProvider
    {
        // Attach-time approval config: the flag rides on the
        // instance, so the clone in the tool call message carries it too.
        $searchTool->requireApproval();

        return new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the search results...'),
        );
    }

    public function test_suspended_run_lives_under_the_thread_workflow_id(): void
    {
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();

        $agent = $this->makeSuspendedRun('thread', new InMemoryMessageStore(), $persistence, $this->makeProvider($searchTool), $searchTool);

        // The thread IS the workflow ID: its partition holds the generation head.
        $this->assertSame('thread', $agent->getWorkflowId());
        $this->assertNotNull($persistence->get('thread', '__ignition'));
    }

    public function test_separate_agents_preserve_a_suspended_conversation_while_another_runs(): void
    {
        $messages = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $searchTool->requireApproval();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Second conversation reply'),
            new AssistantMessage('Search results'),
        );
        $agent = $this->makeSuspendedRun('thread-a', $messages, $persistence, $provider, $searchTool);
        $agentRecord = new \NeuronAI\Tests\Support\ExecutionRecorder($agent);
        $firstRunId = $agent->inspect()?->runId;
        $firstControl = $persistence->get('thread-a', '__control');
        $previous = $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        $previous->set('conversation_marker', 'thread-a');
        $agent->submitInputs(['call_1' => 'approve'], new ApprovalTranslator());

        $otherAgent = Agent::make(workflowId: 'thread-b')->setPersistence($persistence)->setMessageStore($messages);
        $otherAgent->setAiProvider($provider);
        $this->assertSame('thread-a', $previous->get('conversation_marker'));
        $otherAgent->chat(new UserMessage('Second conversation'));

        $this->assertSame('thread-b', $otherAgent->getWorkflowId());
        $this->assertSame($firstControl, $persistence->get('thread-a', '__control'));
        $this->assertCount(1, $provider->getRecorded()[1]->messages);

        $state = $agent->submitInputs(['call_1' => 'approve'], new ApprovalTranslator())->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('Search results', $state->getMessage()->getContent());
        $this->assertSame('thread-a', $agent->getWorkflowId());
        $this->assertSame($firstRunId, $agentRecord->context?->runId);
        $this->assertCount(2, $messages->loadActive('thread-b'));
        $this->assertCount(4, $messages->loadActive('thread-a'));
        $this->assertSame('Search for PHP frameworks', $provider->getRecorded()[2]->messages[0]->getContent());
    }

    public function test_blank_agent_resumes_by_thread(): void
    {
        $messages = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = $this->makeSuspendedRun('thread', $messages, $persistence, $provider, $searchTool);

        // A new execution cycle: only the thread, the shared message store and
        // the shared persistence. This is the core promise.
        $agent2 = Agent::make(workflowId: 'thread');
        $agent2->setMessageStore($messages);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']))->getMessage();

        $this->assertSame($agent1->inspect()?->runId, $agent2->inspect()?->runId);
        $this->assertSame('Here are the search results...', $message->getContent());
        // ChatNode:0 was memoized under the resolved workflow ID — the first inference is not re-billed.
        $this->assertSame(2, $provider->getCallCount());
    }

    public function test_thread_first_resume_with_explicit_thread_id_and_a_shared_store(): void
    {
        // The one-wiring-expression promise: identical make(workflowId:) + store
        // wiring for the fresh run and the thread-first resume.
        $messages = new SqliteMessageStore();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = Agent::make(workflowId: 'thread-cont');
        $agent1->setMessageStore($messages);
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);

        $this->assertTrue($agent1->chat(new UserMessage('Search for PHP frameworks'))->isInterrupted());
        $this->assertSame('thread-cont', $agent1->getWorkflowId());
        $this->assertNotNull($persistence->get('thread-cont', '__ignition'));

        $agent2 = Agent::make(workflowId: 'thread-cont');
        $agent2->setMessageStore($messages);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']))->getMessage();

        $this->assertSame($agent1->inspect()?->runId, $agent2->inspect()?->runId);
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function test_completed_run_reads_as_no_run_in_flight(): void
    {
        $messages = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $this->makeSuspendedRun('thread', $messages, $persistence, $provider, $searchTool);

        $agent2 = Agent::make(workflowId: 'thread');
        $agent2->setMessageStore($messages);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);
        $agent2->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']));

        // Completion swept the thread's partition: nothing survives, and a
        // further thread-first continuation has nothing to continue.
        $this->assertNull($persistence->get('thread', '__ignition'));

        $agent3 = Agent::make(workflowId: 'thread');
        $agent3->setMessageStore($messages);
        $agent3->setAiProvider($provider);
        $agent3->addTool($searchTool);
        $agent3->setPersistence($persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No run in flight for workflow ID');

        $agent3->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']));
    }

    public function test_constructor_identity_cannot_be_changed_through_the_thread_setter(): void
    {
        $agent = Agent::make(workflowId: 'conversation');
        $this->expectException(WorkflowException::class);
        $agent->setThreadId('another-conversation');
    }

    public function test_resume_with_nothing_in_flight_fails_loudly(): void
    {
        // A thread-keyed continuation of a thread with nothing in flight is
        // an unidentifiable request: it fails loudly rather than silently
        // running against a wrong or absent run.
        $agent = Agent::make(workflowId: 'thread');
        $agent->setMessageStore(new InMemoryMessageStore());
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));
        $agent->setPersistence(new InMemoryPersistence());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No run in flight for workflow ID');

        $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']));
    }

    public function test_explicit_workflow_id_resume_fails_on_missing_ignition(): void
    {
        // An explicit workflow ID agreeing with the thread resolves fine — and
        // then fails on the missing generation head, not on identity.
        $agent = Agent::make(workflowId: 'my_explicit_run');
        $agent->setMessageStore(new InMemoryMessageStore());
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for workflow ID 'my_explicit_run'");

        $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']));
    }

    public function test_same_instance_resume_keeps_own_run_id(): void
    {
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent = $this->makeSuspendedRun('thread', new InMemoryMessageStore(), $persistence, $provider, $searchTool);
        $runId = $agent->inspect()?->runId;

        // Resume on the SAME instance: its already-resolved identity is
        // non-null, so it wins — no re-resolution happens at all.
        $state = $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']));
        $message = $state->getMessage();

        $this->assertSame($runId, $state->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function test_new_turn_is_refused_while_an_approval_is_pending(): void
    {
        $messages = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $suspended = $this->makeSuspendedRun('thread', $messages, $persistence, $provider, $searchTool);

        // The thread is held by a live pause, not a dead run: the refusal
        // names the awaited event so the caller knows how to settle it.
        $agent2 = Agent::make(workflowId: 'thread');
        $agent2->setMessageStore($messages);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        try {
            $agent2->chat(new UserMessage('Never mind, something else'));
            $this->fail('A pending approval should refuse a new turn.');
        } catch (RunInFlightException $e) {
            $this->assertSame('thread', $e->workflowId);
            $this->assertSame($suspended->inspect()?->runId, $e->runId);
            $this->assertSame(WorkflowStatus::Suspended, $e->status);
            $this->assertInstanceOf(ApprovalRequest::class, $e->interrupt);
            $this->assertStringContainsString("wait_for_event 'approval'", $e->getMessage());
        }

        // Nothing was disturbed: the approval is still deliverable.
        $agent3 = Agent::make(workflowId: 'thread');
        $agent3->setMessageStore($messages);
        $agent3->setAiProvider($provider);
        $agent3->addTool($searchTool);
        $agent3->setPersistence($persistence);

        $message = $agent3->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']))->getMessage();

        $this->assertSame('Here are the search results...', $message->getContent());
    }
}
