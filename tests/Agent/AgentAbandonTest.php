<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
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
        InMemoryMessageStore $messageStore,
        InMemoryPersistence $persistence,
        ?SearchTool $tool = null,
    ): Agent {
        $agent = Agent::make(workflowId: 'thread');
        $agent->setMessageStore($messageStore);
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
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $tool = new SearchTool();
        $provider = $this->approvalProvider($tool);

        $suspended = $this->makeAgent($provider, $messageStore, $persistence, $tool);
        $this->assertTrue($suspended->chat(new UserMessage('Search for PHP frameworks'))->isInterrupted());
        $threadId = 'thread';

        try {
            $this->makeAgent($provider, $messageStore, $persistence, $tool)->abandon();
            $this->fail('A pending approval should refuse abandonment.');
        } catch (AgentException $e) {
            $this->assertStringContainsString('submitInputs()', $e->getMessage());
        }

        // Nothing was disturbed: the run and its history tail are untouched
        // and the approval is still deliverable.
        $this->assertNotNull($persistence->get($threadId, '__control'));
        $this->assertCount(2, $messageStore->loadActive('thread'));

        $message = $this->makeAgent($provider, $messageStore, $persistence, $tool)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['call_1' => 'approve']))
            ->getMessage();
        $this->assertSame('Here are the search results...', $message->getContent());
    }

    public function test_abandon_discards_a_failed_turn_without_touching_history(): void
    {
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();

        try {
            $this->makeAgent($provider, $messageStore, $persistence)->chat(new UserMessage('Hello'));
            $this->fail('Expected the provider failure to propagate.');
        } catch (ProviderException) {
        }
        $threadId = 'thread';
        $this->assertNotNull($persistence->get($threadId, '__control'));

        $this->assertTrue($this->makeAgent($provider, $messageStore, $persistence)->abandon());

        $this->assertNull($persistence->get($threadId, '__control'));
        $this->assertCount(0, $messageStore->loadActive('thread'));
    }

    public function test_abandon_reports_nothing_in_flight_as_false(): void
    {
        $agent = $this->makeAgent(new FakeAIProvider(), new InMemoryMessageStore(), new InMemoryPersistence());

        $this->assertFalse($agent->abandon());
    }

    public function test_reset_conversation_frees_the_thread_even_with_a_pending_approval(): void
    {
        $messageStore = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $tool = new SearchTool();
        $provider = $this->approvalProvider($tool);

        $this->makeAgent($provider, $messageStore, $persistence, $tool)->chat(new UserMessage('Search for PHP frameworks'));
        $threadId = 'thread';

        $this->makeAgent($provider, $messageStore, $persistence, $tool)->resetConversation();

        $this->assertNull($persistence->get($threadId, '__control'));
        $this->assertCount(0, $messageStore->loadActive('thread'));

        // The thread is a blank slate: a new turn ignites instead of being refused.
        $message = $this->makeAgent($provider, $messageStore, $persistence, $tool)
            ->chat(new UserMessage('Hello again'))
            ->getMessage();
        $this->assertSame('Here are the search results...', $message->getContent());
    }
}
