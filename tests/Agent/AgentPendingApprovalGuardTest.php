<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Throwable;

use function str_contains;

class AgentPendingApprovalGuardTest extends TestCase
{
    private function agentWithPendingApproval(): Agent
    {
        $tool = ToolDefinition::make('delete_file', 'dangerous')
            ->setInputs(['path' => '/tmp/x'])
            ->setCallId('c1');
        $tool->setApprovalState(ApprovalState::Pending);

        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('go'));
        $history->addMessage(new ToolCallMessage(tools: [$tool]));

        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('ok')));
        $agent->setChatHistory($history);

        return $agent;
    }

    public function test_chat_throws_when_last_message_has_pending_approval(): void
    {
        $agent = $this->agentWithPendingApproval();

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('awaiting approval');

        $agent->chat(new UserMessage('another turn'));
    }

    public function test_chat_with_payload_bypasses_guard(): void
    {
        $agent = $this->agentWithPendingApproval();

        // Resuming delivers decisions; the guard must not fire. (Anything that fails
        // later is an unrelated fake-provider/resume concern, not the guard.)
        $threwGuard = false;
        try {
            $agent->chat(new UserMessage('another turn'), ['c1' => 'approve']);
        } catch (AgentException $e) {
            $threwGuard = str_contains($e->getMessage(), 'awaiting approval');
        } catch (Throwable) {
            // unrelated — not the guard
        }

        $this->assertFalse($threwGuard, 'The pending-approval guard must be bypassed when resuming with a payload');
    }

    public function test_chat_on_clean_history_passes(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('hello')));
        $agent->setChatHistory(new InMemoryChatHistory());

        // No exception from the guard (an empty history has no pending approvals).
        $threwGuard = false;
        try {
            $agent->chat(new UserMessage('hi'));
        } catch (AgentException $e) {
            $threwGuard = str_contains($e->getMessage(), 'awaiting approval');
        } catch (Throwable) {
            // unrelated
        }

        $this->assertFalse($threwGuard, 'The guard must not fire on a clean history');
    }
}
