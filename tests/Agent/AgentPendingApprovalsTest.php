<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\ClosureDependencyTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;

/**
 * An answered approval request stays attached to the run until its tools
 * settle: only a suspended run is still waiting for a decision.
 */
class AgentPendingApprovalsTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected InMemoryMessageStore $messages;

    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->messages = new InMemoryMessageStore();
        $this->provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('count_users', 'call_count')]),
            new AssistantMessage('One user'),
        );
    }

    public function test_approvals_being_executed_are_not_pending(): void
    {
        $whileExecuting = null;
        $tool = (new ClosureDependencyTool(function () use (&$whileExecuting): int {
            $whileExecuting = $this->pendingApprovalIds();
            return 1;
        }))->requireApproval();
        $this->agent($tool)->chat(new UserMessage('How many users?'));
        $this->assertSame(['call_count'], $this->pendingApprovalIds());

        $this->agent($tool)->submitApprovalDecisions(['call_count' => 'approve'])->run();

        $this->assertSame([], $whileExecuting);
    }

    public function test_approvals_of_a_failed_run_are_not_pending(): void
    {
        $tool = (new ClosureDependencyTool(fn (): int => throw new RuntimeException('Database unavailable')))->requireApproval();
        $this->agent($tool)->chat(new UserMessage('How many users?'));

        try {
            $this->agent($tool)->submitApprovalDecisions(['call_count' => 'approve'])->run();
            $this->fail('The approved tool must fail the run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database unavailable', $exception->getMessage());
        }

        $run = $this->reader()->inspect();
        $this->assertSame(WorkflowStatus::Failed, $run?->status);
        $this->assertNotNull($run->interrupt);
        $this->assertSame([], $this->pendingApprovalIds());
    }

    protected function agent(ToolInterface $tool): Agent
    {
        $agent = $this->reader();
        $agent->setMessageStore($this->messages)->setAiProvider($this->provider);
        $agent->addTool($tool);

        return $agent;
    }

    /**
     * A fresh instance per read, as a page reload would build it.
     */
    protected function reader(): Agent
    {
        $agent = Agent::make(workflowId: 'approvals');
        $agent->setPersistence($this->persistence);

        return $agent;
    }

    /**
     * @return string[]
     */
    protected function pendingApprovalIds(): array
    {
        return array_map(static fn (Action $action): string => $action->id, $this->reader()->pendingApprovals());
    }
}
