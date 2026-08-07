<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Tools\SearchTool;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0005: the runId (resume handle) is stamped on the suspended ToolCallMessage in chat
 * history, and a payload-carrying chat()/stream()/structured() call with no explicit
 * runId adopts it from the history tail.
 */
class AgentRunIdAdoptionTest extends TestCase
{
    protected function makeSuspendedRun(
        InMemoryChatHistory $history,
        WorkflowExecutor $executor,
        FakeAIProvider $provider,
        SearchTool $searchTool,
    ): Agent {
        $agent = Agent::make();
        $agent->setChatHistory($history);
        $agent->setAiProvider($provider);
        $agent->addTool($searchTool);
        $agent->setExecutor($executor);

        $handler = $agent->chat(new UserMessage('Search for PHP frameworks'));
        $handler->run();

        $this->assertTrue($handler->interrupted());

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

    public function testSuspendStampsRunIdOnHistoryTail(): void
    {
        $history = new InMemoryChatHistory();
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));
        $searchTool = new SearchTool();

        $agent = $this->makeSuspendedRun($history, $executor, $this->makeProvider($searchTool), $searchTool);

        $tail = $history->getLastMessage();

        $this->assertInstanceOf(ToolCallMessage::class, $tail);
        $this->assertSame($agent->getRunId(), $tail->getRunId());
    }

    public function testResumeAdoptsRunIdFromHistory(): void
    {
        $history = new InMemoryChatHistory();
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = $this->makeSuspendedRun($history, $executor, $provider, $searchTool);

        // A new execution cycle: NO runId — only the shared chat history.
        $agent2 = Agent::make();
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setExecutor($executor);

        $message = $agent2->chat(payload: ['call_1' => 'approve'])->getMessage();

        $this->assertSame($agent1->getRunId(), $agent2->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
        // ChatNode:0 was memoized under the adopted id — the first inference is not re-billed.
        $this->assertSame(2, $provider->getCallCount());
    }

    public function testHistoryRunIdOverridesExplicitRunId(): void
    {
        $history = new InMemoryChatHistory();
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent1 = $this->makeSuspendedRun($history, $executor, $provider, $searchTool);

        // Chat history is the system of record: the stamped token wins even over
        // an explicit (here: wrong) runId passed to make().
        $agent2 = Agent::make(runId: 'some_other_workflow');
        $agent2->setChatHistory($history);
        $agent2->setAiProvider($provider);
        $agent2->addTool($searchTool);
        $agent2->setExecutor($executor);

        $message = $agent2->chat(payload: ['call_1' => 'approve'])->getMessage();

        $this->assertSame($agent1->getRunId(), $agent2->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function testKeepsOwnRunIdWhenHistoryHasNoStamp(): void
    {
        // A payload against a thread with no stamped tail (empty history here) leaves
        // the runId untouched — the fallback that keeps explicit-token resumes
        // of non-approval suspensions working.
        $agent = Agent::make(runId: 'my_explicit_token');
        $agent->setChatHistory(new InMemoryChatHistory());
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));

        $agent->chat(payload: ['call_1' => 'approve']);

        $this->assertSame('my_explicit_token', $agent->getRunId());
    }

    public function testSameInstanceResumeKeepsOwnRunId(): void
    {
        $history = new InMemoryChatHistory();
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));
        $searchTool = new SearchTool();
        $provider = $this->makeProvider($searchTool);

        $agent = $this->makeSuspendedRun($history, $executor, $provider, $searchTool);
        $runId = $agent->getRunId();

        // Resume on the SAME instance: the interrupted in-memory state short-circuits
        // adoption and the agent keeps its own id.
        $message = $agent->chat(payload: ['call_1' => 'approve'])->getMessage();

        $this->assertSame($runId, $agent->getRunId());
        $this->assertSame('Here are the search results...', $message->getContent());
    }
}
