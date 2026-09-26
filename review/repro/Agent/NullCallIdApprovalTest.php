<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

class NullCallIdApprovalTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected InMemoryMessageStore $messages;

    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->messages = new InMemoryMessageStore();
        $this->provider = new FakeAIProvider();
        CountingTool::reset();
    }

    protected function agent(): Agent
    {
        return Agent::make(workflowId: 'null-call-id')
            ->setPersistence($this->persistence)
            ->setMessageStore($this->messages)
            ->setAiProvider($this->provider)
            ->addTool((new CountingTool())->requireApproval());
    }

    public function test_approving_the_advertised_action_of_a_call_without_id_runs_the_tool(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('lookup', null, ['query' => 'PHP'])]),
            new AssistantMessage('Done'),
        );

        $request = $this->agent()->chat(new UserMessage('Look it up'))->getInterruptRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $actionId = $request->getActions()[0]->id;

        $state = $this->agent()->submitApprovalDecisions([$actionId => 'approve'])->run();

        $this->assertNull($state->getInterruptRequest(), 'Approving the advertised action must settle the gate');
        $this->assertSame(1, CountingTool::$executions);
    }
}
