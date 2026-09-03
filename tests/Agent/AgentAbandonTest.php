<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

/**
 * Abandoning at the Agent level: a dead turn is discarded freely (its inbound
 * message was never committed), while a turn paused on an approval refuses,
 * because its pre-suspend ToolCallMessage would be left unanswered in history.
 * resetConversation() frees the thread unconditionally since it wipes history.
 */
class AgentAbandonTest extends TestCase
{
    protected function makeAgent(
        FakeAIProvider $provider,
        InMemoryChatHistory $history,
        InMemoryPersistence $persistence,
        ?SearchTool $tool = null,
    ): Agent {
        $agent = Agent::make();
        $agent->setChatHistory($history);
        $agent->setAiProvider($provider);
        $agent->setPersistence($persistence);
        if ($tool instanceof SearchTool) {
            $agent->addTool($tool);
        }

        return $agent;
    }

    protected function approvalProvider(SearchTool $tool): FakeAIProvider
    {
        $tool->requireApproval();

        return new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP frameworks']),
            ]),
            new AssistantMessage('Here are the search results...'),
        );
    }

    public function test_abandon_refuses_while_an_approval_is_pending_and_leaves_the_run_intact(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $tool = new SearchTool();
        $provider = $this->approvalProvider($tool);

        $suspended = $this->makeAgent($provider, $history, $persistence, $tool);
        $this->assertTrue($suspended->chat(new UserMessage('Search for PHP frameworks'))->isInterrupted());
        $threadId = (string) $history->getThreadId();

        try {
            $this->makeAgent($provider, $history, $persistence, $tool)->abandonRun();
            $this->fail('A pending approval should refuse abandonment.');
        } catch (AgentException $e) {
            $this->assertStringContainsString('toolApprovalDecisions()', $e->getMessage());
        }

        // Nothing was disturbed: the run and its history tail are untouched
        // and the approval is still deliverable.
        $this->assertNotNull($persistence->get($threadId, '__control'));
        $this->assertCount(2, $history->getMessages());

        $message = $this->makeAgent($provider, $history, $persistence, $tool)
            ->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['call_1' => 'approve'])])
            ->getMessage();
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function test_abandon_discards_a_failed_turn_without_touching_history(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();

        try {
            $this->makeAgent($provider, $history, $persistence)->chat(new UserMessage('Hello'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }
        $threadId = (string) $history->getThreadId();
        $this->assertNotNull($persistence->get($threadId, '__control'));

        $this->assertTrue($this->makeAgent($provider, $history, $persistence)->abandonRun());

        $this->assertNull($persistence->get($threadId, '__control'));
        $this->assertCount(0, $history->getMessages());
    }

    public function test_abandon_reports_nothing_in_flight_as_false(): void
    {
        $agent = $this->makeAgent(new FakeAIProvider(), new InMemoryChatHistory(), new InMemoryPersistence());

        $this->assertFalse($agent->abandonRun());
    }

    public function test_reset_conversation_frees_the_thread_even_with_a_pending_approval(): void
    {
        $history = new InMemoryChatHistory();
        $persistence = new InMemoryPersistence();
        $tool = new SearchTool();
        $provider = $this->approvalProvider($tool);

        $this->makeAgent($provider, $history, $persistence, $tool)->chat(new UserMessage('Search for PHP frameworks'));
        $threadId = (string) $history->getThreadId();

        $this->makeAgent($provider, $history, $persistence, $tool)->resetConversation();

        $this->assertNull($persistence->get($threadId, '__control'));
        $this->assertCount(0, $history->getMessages());

        // The thread is a blank slate: a new turn ignites instead of being refused.
        $message = $this->makeAgent($provider, $history, $persistence, $tool)
            ->chat(new UserMessage('Hello again'))
            ->getMessage();
        $this->assertSame('Here are the search results...', $message->getContent());
    }
}
