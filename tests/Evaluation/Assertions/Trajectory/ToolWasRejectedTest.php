<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasRejected;
use NeuronAI\Tests\Support\TrajectoryAssertionTestCase;
use NeuronAI\Tools\ApprovalState;

class ToolWasRejectedTest extends TrajectoryAssertionTestCase
{
    public function test_passes_when_tool_was_rejected(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Rejected, 'amount too high');
        $tool->setResult('rejected by the user');

        $result = (new ToolWasRejected('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function test_fails_when_tool_was_approved(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Approved);
        $tool->setResult('refunded');

        $result = (new ToolWasRejected('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame("Tool 'refund_order' was called, but no call was rejected (approval states: approved)", $result->message);
    }

    public function test_passes_when_any_call_of_the_tool_was_rejected(): void
    {
        $approved = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1');
        $approved->setApprovalState(ApprovalState::Approved);
        $rejected = $this->makeTool('refund_order', ['order_id' => '2'], 'call_2');
        $rejected->setApprovalState(ApprovalState::Rejected, 'wrong order');

        $result = (new ToolWasRejected('refund_order'))->evaluate($this->trajectoryWithTools($approved, $rejected));

        $this->assertTrue($result->passed);
    }

    public function test_lists_the_approval_state_of_every_call(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1');
        $pending->setApprovalState(ApprovalState::Pending);
        $ungated = $this->makeTool('refund_order', ['order_id' => '2'], 'call_2');

        $result = (new ToolWasRejected('refund_order'))->evaluate($this->trajectoryWithTools($pending, $ungated));

        $this->assertFalse($result->passed);
        $this->assertSame(
            "Tool 'refund_order' was called, but no call was rejected (approval states: pending, not approval-gated)",
            $result->message
        );
    }

    public function test_fails_when_tool_was_never_called(): void
    {
        $result = (new ToolWasRejected('refund_order'))->evaluate($this->emptyTrajectory());

        $this->assertFalse($result->passed);
        $this->assertSame("Expected tool 'refund_order' to be rejected, but it was never called (no tool was called)", $result->message);
    }
}
