<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Tools\SearchTool;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Thread-first continuation: the Agent declares its threadId as the run's
 * correlation key, so the engine binds threadId → runId in workflow
 * persistence at ignition. The approve/deny endpoint needs only the thread —
 * no runId is stored anywhere by the application.
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
        // Attach-time approval config (ADR 0009): the flag rides on the
        // instance, so the clone in the tool call message carries it too.
        $searchTool->requireApproval();

        return new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($searchTool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the search results...'),
        );
    }

    public function testSuspendBindsThreadPointer(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();

        $agent = $this->makeSuspendedRun($history, $persistence, $this->makeProvider($searchTool), $searchTool);

        $this->assertSame($agent->getRunId(), $persistence->get('__correlation', $history->getThreadId()));
    }

    public function testBlankAgentResumesByThread(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = $this->makeSuspendedRun($history, $persistence, $provider, $searchTool);

        // A new execution cycle: NO runId — only the thread (chat history) and
        // the shared persistence. This is the core promise.
        $agent2 = Agent::make();
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume(['call_1' => 'approve'])->getMessage();

        $this->assertSame($agent1->getRunId(), $agent2->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
        // ChatNode:0 was memoized under the resolved id — the first inference is not re-billed.
        $this->assertSame(2, $provider->getCallCount());
    }

    public function testThreadFirstResumeWithExplicitThreadIdAndUnboundHistory(): void
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
        $this->assertSame($agent1->getRunId(), $persistence->get('__correlation', 'thread-cont'));

        $agent2 = Agent::make(threadId: 'thread-cont');
        $agent2->setChatHistory(new SQLChatHistory($pdo));
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume(['call_1' => 'approve'])->getMessage();

        $this->assertSame($agent1->getRunId(), $agent2->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function testCompletedRunReadsAsNoRunInFlight(): void
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
        $agent2->resume(['call_1' => 'approve']);

        // The pointer row survives completion as a historical fact; the
        // thread reads as free because the run's partition was deleted.
        $this->assertNotNull($persistence->get('__correlation', $history->getThreadId()));

        $agent3 = Agent::make();
        $agent3->setChatHistory($history);
        $agent3->setAiProvider($provider);
        $agent3->addTool($searchTool);
        $agent3->setPersistence($persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No run in flight for correlation key');

        $agent3->resume(['call_1' => 'approve']);
    }

    public function testExplicitRunIdWinsOverThreadPointer(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = $this->makeSuspendedRun($history, $persistence, $provider, $searchTool);

        // The pointer moved on (a later run claimed the thread). An explicit
        // runId is authoritative and addresses the run it names.
        $persistence->put('__correlation', $history->getThreadId(), 'some_other_workflow');

        $agent2 = Agent::make(runId: $agent1->getRunId());
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setPersistence($persistence);

        $message = $agent2->resume(['call_1' => 'approve'])->getMessage();

        $this->assertSame($agent1->getRunId(), $agent2->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function testResumeWithoutPointerFailsLoudly(): void
    {
        // A thread-addressed continuation of a thread with nothing in flight is
        // an unaddressable request: it fails loudly rather than silently
        // running against a wrong or absent run.
        $agent = Agent::make();
        $agent->setChatHistory(new InMemoryChatHistory());
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));
        $agent->setPersistence(new InMemoryPersistence());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No run in flight for correlation key');

        $agent->resume(['call_1' => 'approve']);
    }

    public function testExplicitRunIdResumeNeedsNoPointer(): void
    {
        // The positive counterpart: an explicit runId never consults the
        // pointer — it fails on the missing ignition record instead.
        $agent = Agent::make(runId: 'my_explicit_run');
        $agent->setChatHistory(new InMemoryChatHistory());
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Cannot wake run my_explicit_run');

        $agent->resume(['call_1' => 'approve']);
    }

    public function testSameInstanceResumeKeepsOwnRunId(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent = $this->makeSuspendedRun($history, $persistence, $provider, $searchTool);
        $runId = $agent->getRunId();

        // Resume on the SAME instance: its already-resolved identity is
        // non-null, so it wins — no pointer lookup happens at all.
        $message = $agent->resume(['call_1' => 'approve'])->getMessage();

        $this->assertSame($runId, $agent->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }
}
