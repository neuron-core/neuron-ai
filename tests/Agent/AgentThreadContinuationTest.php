<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
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
        InMemoryChatHistory $history,
        PersistenceInterface $persistence,
        FakeAIProvider $provider,
        SearchTool $searchTool,
    ): Agent {
        $agent = Agent::make();
        $agent->setChatHistory($history);
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
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();

        $agent = $this->makeSuspendedRun($history, $persistence, $this->makeProvider($searchTool), $searchTool);

        // The thread IS the workflow ID: its partition holds the generation head.
        $this->assertSame($history->getThreadId(), $agent->getWorkflowId());
        $this->assertNotNull($persistence->get((string) $history->getThreadId(), '__ignition'));
    }

    public function test_blank_agent_resumes_by_thread(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = $this->makeSuspendedRun($history, $persistence, $provider, $searchTool);

        // A new execution cycle: only the thread (chat history) and the
        // shared persistence. This is the core promise.
        $agent2 = Agent::make();
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])])->getMessage();

        $this->assertSame($agent1->getRunId(), $agent2->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
        // ChatNode:0 was memoized under the resolved workflow ID — the first inference is not re-billed.
        $this->assertSame(2, $provider->getCallCount());
    }

    public function test_thread_first_resume_with_explicit_thread_id_and_unbound_history(): void
    {
        // The binding model's one-wiring-expression promise: identical
        // make(threadId:) + unbound-history wiring for the fresh run and the
        // thread-first resume — identity never appears in wiring code.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id TEXT, role TEXT, content TEXT, meta TEXT
        )');
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = Agent::make(threadId: 'thread-cont');
        $agent1->setChatHistory(new SQLChatHistory($pdo));
        $agent1->setAiProvider($provider);
        $agent1->addTool($searchTool);
        $agent1->setPersistence($persistence);

        $this->assertTrue($agent1->chat(new UserMessage('Search for PHP frameworks'))->isInterrupted());
        $this->assertSame('thread-cont', $agent1->getWorkflowId());
        $this->assertNotNull($persistence->get('thread-cont', '__ignition'));

        $agent2 = Agent::make(threadId: 'thread-cont');
        $agent2->setChatHistory(new SQLChatHistory($pdo));
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])])->getMessage();

        $this->assertSame($agent1->getRunId(), $agent2->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function test_completed_run_reads_as_no_run_in_flight(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $this->makeSuspendedRun($history, $persistence, $provider, $searchTool);

        $agent2 = Agent::make();
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);
        $agent2->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])]);

        // Completion swept the thread's partition: nothing survives, and a
        // further thread-first continuation has nothing to continue.
        $this->assertNull($persistence->get((string) $history->getThreadId(), '__ignition'));

        $agent3 = Agent::make();
        $agent3->setChatHistory($history);
        $agent3->setAiProvider($provider);
        $agent3->addTool($searchTool);
        $agent3->setPersistence($persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No run in flight for workflow ID');

        $agent3->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])]);
    }

    public function test_conflicting_explicit_workflow_id_throws(): void
    {
        // The thread is the declared workflow ID; an explicit workflow ID that
        // disagrees is a misidentified run and must fail loudly, never
        // silently pick one of the two identities.
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent = Agent::make(workflowId: 'someplace_else');
        $agent->setChatHistory($history);
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setPersistence($persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Misidentified run');

        $agent->chat(new UserMessage('Search for PHP frameworks'));
    }

    public function test_resume_with_nothing_in_flight_fails_loudly(): void
    {
        // A thread-keyed continuation of a thread with nothing in flight is
        // an unidentifiable request: it fails loudly rather than silently
        // running against a wrong or absent run.
        $agent = Agent::make();
        $agent->setChatHistory(new InMemoryChatHistory());
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));
        $agent->setPersistence(new InMemoryPersistence());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No run in flight for workflow ID');

        $agent->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])]);
    }

    public function test_explicit_workflow_id_resume_fails_on_missing_ignition(): void
    {
        // An explicit workflow ID agreeing with the thread resolves fine — and
        // then fails on the missing generation head, not on identity.
        $agent = Agent::make(workflowId: 'my_explicit_run');
        $agent->setChatHistory(new InMemoryChatHistory('my_explicit_run'));
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for workflow ID 'my_explicit_run'");

        $agent->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])]);
    }

    public function test_same_instance_resume_keeps_own_run_id(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent = $this->makeSuspendedRun($history, $persistence, $provider, $searchTool);
        $runId = $agent->getRunId();

        // Resume on the SAME instance: its already-resolved identity is
        // non-null, so it wins — no re-resolution happens at all.
        $message = $agent->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])])->getMessage();

        $this->assertSame($runId, $agent->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }
}
