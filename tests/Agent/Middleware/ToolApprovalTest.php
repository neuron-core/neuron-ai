<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolDefinition;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\TestCase;

class ToolApprovalTest extends TestCase
{
    /**
     * A tool that self-declares it requires approval (ADR 0004).
     */
    private function gatedTool(string $name, string $callId, array $inputs = []): Tool
    {
        $tool = new class () extends Tool {
            public function requiresApproval(array $inputs): bool
            {
                return true;
            }

            public function __invoke(mixed ...$arguments): mixed
            {
                return null;
            }
        };

        $tool->setName($name)
            ->setDescription("Gated {$name}")
            ->setCallId($callId)
            ->setInputs($inputs);

        return $tool;
    }

    /**
     * A plain tool that does NOT require approval (ToolDefinition default).
     */
    private function plainTool(string $name, string $callId, array $inputs = []): Tool
    {
        return ToolDefinition::make($name, "Plain {$name}")
            ->setInputs($inputs)
            ->setCallId($callId);
    }

    private function createToolCallEvent(array $tools): ToolCallEvent
    {
        $toolCallMessage = new ToolCallMessage(null, $tools);
        $inferenceEvent = new AIInferenceEvent('test instructions', []);
        return new ToolCallEvent($toolCallMessage, $inferenceEvent);
    }

    private function assertInterrupts(ToolApproval $middleware, ToolNode $node, ToolCallEvent $event, AgentState $state, string $message = ''): WorkflowInterrupt
    {
        $interrupted = false;
        $caught = null;
        try {
            $middleware->before($node, $event, $state);
        } catch (WorkflowInterrupt $e) {
            $interrupted = true;
            $caught = $e;
        }
        $this->assertTrue($interrupted, $message ?: 'Expected WorkflowInterrupt to be thrown');
        /** @var WorkflowInterrupt $caught */
        return $caught;
    }

    private function assertDoesNotInterrupt(ToolApproval $middleware, ToolNode $node, ToolCallEvent $event, AgentState $state, string $message = ''): void
    {
        $interrupted = false;
        try {
            $middleware->before($node, $event, $state);
        } catch (WorkflowInterrupt) {
            $interrupted = true;
        }
        $this->assertFalse($interrupted, $message ?: 'Expected no WorkflowInterrupt');
    }

    /**
     * @return array<string, Action>
     */
    private function actionsById(ApprovalRequest $request): array
    {
        $byId = [];
        foreach ($request->getActions() as $action) {
            $byId[$action->id] = $action;
        }
        return $byId;
    }

    private function lastToolCall(AgentState $state): ToolCallMessage
    {
        $last = $state->getChatHistory()->getLastMessage();
        $this->assertInstanceOf(ToolCallMessage::class, $last);
        return $last;
    }

    public function test_tool_self_declaration_triggers_interrupt(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->gatedTool('delete_file', 'call_a', ['path' => '/tmp/x']);
        $event = $this->createToolCallEvent([$tool]);

        $request = $this->assertInterrupts($middleware, $node, $event, $state)->getRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);

        $byId = $this->actionsById($request);
        $this->assertEquals(ActionDecision::Pending, $byId['call_a']->decision);

        // History now carries the annotated ToolCallMessage with the tool pending.
        $last = $this->lastToolCall($state);
        $this->assertEquals(ApprovalState::Pending, $last->getTools()[0]->getApprovalState());
    }

    public function test_default_tools_do_not_interrupt_with_empty_config(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->plainTool('read_file', 'call_a');
        $event = $this->createToolCallEvent([$tool]);

        $this->assertDoesNotInterrupt($middleware, $node, $event, $state, 'A plain tool with empty config must not interrupt');
        $this->assertCount(0, $state->getChatHistory()->getMessages(), 'History must be untouched');
    }

    public function test_config_string_forces_approval_over_declaration(): void
    {
        $middleware = new ToolApproval(['read_file']);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->plainTool('read_file', 'call_a', ['path' => '/tmp/x']);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertInterrupts($middleware, $node, $event, $state, 'Config string must force approval even though the tool declares false');
    }

    public function test_config_callable_waives_declared_approval(): void
    {
        $middleware = new ToolApproval(['delete_file' => fn (ToolInterface $tool): bool => false]);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->gatedTool('delete_file', 'call_a');
        $event = $this->createToolCallEvent([$tool]);

        $this->assertDoesNotInterrupt($middleware, $node, $event, $state, 'A callable returning false must waive a tool that declares true');
    }

    public function test_incremental_resume_partial_re_suspends_with_progress(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        // First run — suspend for both gated tools.
        $a = $this->gatedTool('a', 'call_a');
        $b = $this->gatedTool('b', 'call_b');
        $this->assertInterrupts($middleware, $node, $this->createToolCallEvent([$a, $b]), $state);

        // Resume with one decision — fresh replayed tools.
        $a2 = $this->gatedTool('a', 'call_a');
        $b2 = $this->gatedTool('b', 'call_b');
        $event = $this->createToolCallEvent([$a2, $b2]);
        $node->setWorkflowContext($state, $event, ['call_a' => 'approve']);

        $request = $this->assertInterrupts($middleware, $node, $event, $state, 'An incomplete set must re-suspend')->getRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);

        $byId = $this->actionsById($request);
        $this->assertEquals(ActionDecision::Approved, $byId['call_a']->decision, 'call_a was approved → progress shown');
        $this->assertEquals(ActionDecision::Pending, $byId['call_b']->decision);

        $last = $this->lastToolCall($state);
        $byCall = [];
        foreach ($last->getTools() as $tool) {
            $byCall[$tool->getCallId()] = $tool;
        }
        $this->assertEquals(ApprovalState::Approved, $byCall['call_a']->getApprovalState());
        $this->assertEquals(ApprovalState::Pending, $byCall['call_b']->getApprovalState());
    }

    public function test_incremental_resume_completes_and_rejects(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $a = $this->gatedTool('a', 'call_a');
        $b = $this->gatedTool('b', 'call_b');
        $this->assertInterrupts($middleware, $node, $this->createToolCallEvent([$a, $b]), $state);

        // Approve a.
        $a2 = $this->gatedTool('a', 'call_a');
        $b2 = $this->gatedTool('b', 'call_b');
        $event1 = $this->createToolCallEvent([$a2, $b2]);
        $node->setWorkflowContext($state, $event1, ['call_a' => 'approve']);
        $this->assertInterrupts($middleware, $node, $event1, $state);

        // Reject b — complete set.
        $a3 = $this->gatedTool('a', 'call_a');
        $b3 = $this->gatedTool('b', 'call_b');
        $event2 = $this->createToolCallEvent([$a3, $b3]);
        $node->setWorkflowContext($state, $event2, ['call_b' => ['reject', 'not now']]);

        $this->assertDoesNotInterrupt($middleware, $node, $event2, $state, 'A complete decision set must proceed');

        $this->assertStringContainsString('TOOL NOT EXECUTED', $b3->getResult());
        $this->assertStringContainsString('not now', $b3->getResult());

        // History reflects the final states.
        $last = $this->lastToolCall($state);
        $byCall = [];
        foreach ($last->getTools() as $tool) {
            $byCall[$tool->getCallId()] = $tool;
        }
        $this->assertEquals(ApprovalState::Approved, $byCall['call_a']->getApprovalState());
        $this->assertEquals(ApprovalState::Rejected, $byCall['call_b']->getApprovalState());
    }

    public function test_decision_overwrite_before_completeness(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $a = $this->gatedTool('a', 'call_a');
        $b = $this->gatedTool('b', 'call_b');
        $this->assertInterrupts($middleware, $node, $this->createToolCallEvent([$a, $b]), $state);

        // Approve a.
        $a2 = $this->gatedTool('a', 'call_a');
        $b2 = $this->gatedTool('b', 'call_b');
        $event1 = $this->createToolCallEvent([$a2, $b2]);
        $node->setWorkflowContext($state, $event1, ['call_a' => 'approve']);
        $this->assertInterrupts($middleware, $node, $event1, $state);

        // Overwrite a to rejected, b still pending → last-write-wins, still suspends.
        $a3 = $this->gatedTool('a', 'call_a');
        $b3 = $this->gatedTool('b', 'call_b');
        $event2 = $this->createToolCallEvent([$a3, $b3]);
        $node->setWorkflowContext($state, $event2, ['call_a' => ['reject', 'changed my mind']]);

        $this->assertInterrupts($middleware, $node, $event2, $state, 'Still incomplete — must re-suspend');

        $last = $this->lastToolCall($state);
        $byCall = [];
        foreach ($last->getTools() as $tool) {
            $byCall[$tool->getCallId()] = $tool;
        }
        $this->assertEquals(ApprovalState::Rejected, $byCall['call_a']->getApprovalState());
        $this->assertEquals('changed my mind', $byCall['call_a']->getApprovalReason());
    }

    public function test_unknown_call_id_in_payload_is_ignored(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $a = $this->gatedTool('a', 'call_a');
        $b = $this->gatedTool('b', 'call_b');
        $this->assertInterrupts($middleware, $node, $this->createToolCallEvent([$a, $b]), $state);

        // Unknown callId — states unchanged, re-suspends.
        $a2 = $this->gatedTool('a', 'call_a');
        $b2 = $this->gatedTool('b', 'call_b');
        $event = $this->createToolCallEvent([$a2, $b2]);
        $node->setWorkflowContext($state, $event, ['bogus' => 'approve']);

        $this->assertInterrupts($middleware, $node, $event, $state);

        $last = $this->lastToolCall($state);
        $byCall = [];
        foreach ($last->getTools() as $tool) {
            $byCall[$tool->getCallId()] = $tool;
        }
        $this->assertEquals(ApprovalState::Pending, $byCall['call_a']->getApprovalState());
        $this->assertEquals(ApprovalState::Pending, $byCall['call_b']->getApprovalState());
    }

    public function test_non_gated_tools_untouched(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $gated = $this->gatedTool('gated', 'call_gated');
        $plain = $this->plainTool('plain', 'call_plain');
        $event = $this->createToolCallEvent([$gated, $plain]);
        $node->setWorkflowContext($state, $event, ['call_gated' => 'approve']);

        $this->assertDoesNotInterrupt($middleware, $node, $event, $state);

        $this->assertEquals(ApprovalState::Approved, $gated->getApprovalState());
        $this->assertNull($plain->getApprovalState(), 'A non-gated tool must keep null approval state');
    }

    public function test_history_write_is_idempotent_across_resumes(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $a = $this->gatedTool('a', 'call_a');
        $b = $this->gatedTool('b', 'call_b');
        $this->assertInterrupts($middleware, $node, $this->createToolCallEvent([$a, $b]), $state);

        // First partial resume.
        $a2 = $this->gatedTool('a', 'call_a');
        $b2 = $this->gatedTool('b', 'call_b');
        $event1 = $this->createToolCallEvent([$a2, $b2]);
        $node->setWorkflowContext($state, $event1, ['call_a' => 'approve']);
        $this->assertInterrupts($middleware, $node, $event1, $state);

        // Second partial resume (overwrite call_a, call_b still pending → re-suspend).
        $a3 = $this->gatedTool('a', 'call_a');
        $b3 = $this->gatedTool('b', 'call_b');
        $event2 = $this->createToolCallEvent([$a3, $b3]);
        $node->setWorkflowContext($state, $event2, ['call_a' => ['reject', 'flip']]);
        $this->assertInterrupts($middleware, $node, $event2, $state);

        $toolCallCount = 0;
        foreach ($state->getChatHistory()->getMessages() as $message) {
            if ($message instanceof ToolCallMessage) {
                $toolCallCount++;
            }
        }
        $this->assertSame(1, $toolCallCount, 'Exactly one ToolCallMessage across initial run + two resumes');
    }

    public function test_non_tool_call_event_is_ignored(): void
    {
        $middleware = new ToolApproval(['some_tool']);
        $node = new ToolNode();
        $state = new AgentState();

        $event = new AIInferenceEvent('instructions', []);

        $interrupted = false;
        try {
            $middleware->before($node, $event, $state);
        } catch (WorkflowInterrupt) {
            $interrupted = true;
        }
        $this->assertFalse($interrupted, 'Non-ToolCallEvent should be ignored');
    }
}
