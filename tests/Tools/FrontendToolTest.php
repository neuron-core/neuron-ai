<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Workflow\Interrupt\FrontendRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ToolsInterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\TestCase;

class FrontendToolTest extends TestCase
{
    // ── FrontendRequest value object ──

    public function test_frontend_request_constructor(): void
    {
        $request = new FrontendRequest('user-picker', ['role' => 'admin'], 'Select a user');

        $this->assertSame('user-picker', $request->getHandler());
        $this->assertSame(['role' => 'admin'], $request->getPayload());
        $this->assertSame('Select a user', $request->getMessage());
    }

    public function test_frontend_request_default_message(): void
    {
        $request = new FrontendRequest('confirm-action', ['action' => 'delete']);

        $this->assertSame('Frontend handler: confirm-action', $request->getMessage());
    }

    public function test_frontend_request_json_serialize(): void
    {
        $request = new FrontendRequest('user-picker', ['role' => 'admin'], 'Select a user');

        $serialized = $request->jsonSerialize();
        $this->assertSame('user-picker', $serialized['handler']);
        $this->assertSame(['role' => 'admin'], $serialized['payload']);
        $this->assertSame('Select a user', $serialized['message']);
    }

    // ── FrontendTool basic construction ──

    public function test_frontend_tool_construction(): void
    {
        $tool = new FrontendTool(
            'pick_user',
            'user-picker',
            'Open a modal to select a user',
            [ToolProperty::make('role', PropertyType::STRING, 'Filter by role', true)],
        );

        $this->assertSame('pick_user', $tool->getName());
        $this->assertSame('user-picker', $tool->getHandler());
        $this->assertSame('Open a modal to select a user', $tool->getDescription());
        $this->assertCount(1, $tool->getProperties());
        $this->assertSame('role', $tool->getProperties()[0]->getName());
    }

    // ── FrontendTool __invoke without resume ──

    public function test_frontend_tool_invoke_signals_interrupt(): void
    {
        $tool = new FrontendTool('pick_user', 'user-picker', 'Pick a user');

        $result = $tool->__invoke(role: 'admin');

        $this->assertSame('', $result);

        $interrupt = $tool->getInterruptRequest();
        $this->assertInstanceOf(FrontendRequest::class, $interrupt);
        $this->assertSame('user-picker', $interrupt->getHandler());
        $this->assertSame(['role' => 'admin'], $interrupt->getPayload());
    }

    public function test_frontend_tool_invoke_with_multiple_params(): void
    {
        $tool = new FrontendTool('pick_user', 'user-picker', 'Pick a user');

        $tool->__invoke(role: 'admin', department: 'engineering');

        $interrupt = $tool->getInterruptRequest();
        $this->assertInstanceOf(FrontendRequest::class, $interrupt);
        $this->assertSame(['role' => 'admin', 'department' => 'engineering'], $interrupt->getPayload());
    }

    // ── FrontendTool __invoke with resume ──

    public function test_frontend_tool_resume_with_frontend_request(): void
    {
        $tool = new FrontendTool('pick_user', 'user-picker', 'Pick a user');

        $resume = new FrontendRequest('user-picker', ['user_id' => 42], 'User selected');
        $tool->setResumeRequest($resume);

        $result = $tool->__invoke(role: 'admin');

        $this->assertSame('{"user_id":42}', $result);
        $this->assertNull($tool->getInterruptRequest());
    }

    public function test_frontend_tool_resume_with_generic_request(): void
    {
        $tool = new FrontendTool('confirm', 'confirm-action', 'Confirm an action');

        $resume = new class ('User approved') extends InterruptRequest {
            public function jsonSerialize(): array
            {
                return ['message' => $this->message];
            }
        };
        $tool->setResumeRequest($resume);

        $result = $tool->__invoke(action: 'delete');

        $this->assertSame('User approved', $result);
        $this->assertNull($tool->getInterruptRequest());
    }

    // ── FrontendTool via ToolNode integration ──

    public function test_frontend_tool_triggers_interrupt_through_tool_node(): void
    {
        $tool = new FrontendTool('pick_user', 'user-picker', 'Pick a user');
        $tool->setInputs(['role' => 'admin']);

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event);

        $this->expectException(WorkflowInterrupt::class);
        $generator = $toolNode($event, $state);
        foreach ($generator as $_) {
            $_ = null;
        }
    }

    public function test_frontend_tool_resume_through_tool_node(): void
    {
        $tool = new FrontendTool('pick_user', 'user-picker', 'Pick a user');
        $tool->setInputs(['role' => 'admin']);

        $resumeRequest = new ToolsInterruptRequest('resume');
        $resumeRequest->addRequest('pick_user', new FrontendRequest('user-picker', ['user_id' => 42], 'done'));

        $toolNode = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext($state, $event, $resumeRequest);
        $generator = $toolNode($event, $state);
        foreach ($generator as $_) {
            $_ = null;
        }

        $this->assertSame('{"user_id":42}', $tool->getResult());
    }
}
