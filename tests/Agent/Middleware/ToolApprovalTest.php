<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\TestCase;

class ToolApprovalTest extends TestCase
{
    private function createToolWithInputs(string $name, array $inputs = []): Tool
    {
        $tool = Tool::make($name, "Description for {$name}");
        $tool->setInputs($inputs);
        $tool->setCallId("call_{$name}");
        return $tool;
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

    public function test_empty_tools_array_requires_approval_for_all(): void
    {
        $middleware = new ToolApproval();
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('any_tool');
        $event = $this->createToolCallEvent([$tool]);

        $this->assertInterrupts($middleware, $node, $event, $state);
    }

    public function test_string_entry_always_requires_approval(): void
    {
        $middleware = new ToolApproval(['delete_file']);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('delete_file', ['path' => '/tmp/test.txt']);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertInterrupts($middleware, $node, $event, $state);
    }

    public function test_string_entry_does_not_match_unrelated_tool(): void
    {
        $middleware = new ToolApproval(['delete_file']);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('read_file', ['path' => '/tmp/test.txt']);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertDoesNotInterrupt($middleware, $node, $event, $state, 'read_file should not require approval');
    }

    public function test_callable_returning_true_requires_approval(): void
    {
        $middleware = new ToolApproval([
            'transfer_money' => fn (array $args): bool => ($args['amount'] ?? 0) > 100,
        ]);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('transfer_money', ['amount' => 200, 'to' => 'alice']);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertInterrupts($middleware, $node, $event, $state);
    }

    public function test_callable_returning_false_skips_approval(): void
    {
        $middleware = new ToolApproval([
            'transfer_money' => fn (array $args): bool => ($args['amount'] ?? 0) > 100,
        ]);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('transfer_money', ['amount' => 50, 'to' => 'alice']);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertDoesNotInterrupt($middleware, $node, $event, $state, 'transfer_money with amount 50 should not require approval');
    }

    public function test_mixed_array_unconditional_tool_interrupts(): void
    {
        $middleware = new ToolApproval([
            'delete_file',
            'transfer_money' => fn (array $args): bool => ($args['amount'] ?? 0) > 100,
        ]);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('delete_file', ['path' => '/tmp/x']);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertInterrupts($middleware, $node, $event, $state);
    }

    public function test_mixed_array_conditional_below_threshold_passes(): void
    {
        $middleware = new ToolApproval([
            'delete_file',
            'transfer_money' => fn (array $args): bool => ($args['amount'] ?? 0) > 100,
        ]);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('transfer_money', ['amount' => 50]);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertDoesNotInterrupt($middleware, $node, $event, $state, 'transfer_money with amount 50 should not require approval');
    }

    public function test_mixed_array_unrelated_tool_passes(): void
    {
        $middleware = new ToolApproval([
            'delete_file',
            'transfer_money' => fn (array $args): bool => ($args['amount'] ?? 0) > 100,
        ]);
        $node = new ToolNode();
        $state = new AgentState();

        $tool = $this->createToolWithInputs('read_file', ['path' => '/tmp/safe.txt']);
        $event = $this->createToolCallEvent([$tool]);

        $this->assertDoesNotInterrupt($middleware, $node, $event, $state, 'read_file should not require approval');
    }

    public function test_non_tool_call_event_is_ignored(): void
    {
        $middleware = new ToolApproval(['some_tool']);
        $node = new ToolNode();
        $state = new AgentState();

        // Create a dummy ToolCallEvent with no tools, then pass a different event type
        // to verify the early return on line 53-55 of ToolApproval
        $event = new AIInferenceEvent('instructions', []);

        $interrupted = false;
        try {
            /** @phpstan-ignore argument.type */
            $middleware->before($node, $event, $state);
        } catch (WorkflowInterrupt) {
            $interrupted = true;
        }
        $this->assertFalse($interrupted, 'Non-ToolCallEvent should be ignored');
    }

    public function test_multiple_tools_only_matching_ones_require_approval(): void
    {
        $middleware = new ToolApproval([
            'dangerous_tool',
            'conditional_tool' => fn (array $args): bool => ($args['risk'] ?? '') === 'high',
        ]);
        $node = new ToolNode();
        $state = new AgentState();

        // Two tools: one unconditional match, one conditional that does NOT match
        $tool1 = $this->createToolWithInputs('dangerous_tool', ['target' => 'x']);
        $tool2 = $this->createToolWithInputs('conditional_tool', ['risk' => 'low']);
        $event = $this->createToolCallEvent([$tool1, $tool2]);

        $interrupt = $this->assertInterrupts($middleware, $node, $event, $state, 'Should have interrupted for dangerous_tool');

        $request = $interrupt->getRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        /** @var ApprovalRequest $request */
        $actions = $request->getActions();
        $this->assertCount(1, $actions);
        $this->assertSame('dangerous_tool', $actions[0]->name);
    }
}
