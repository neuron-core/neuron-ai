<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasApproved;
use NeuronAI\Tests\Support\TrajectoryAssertionTestCase;
use NeuronAI\Tools\ApprovalState;

class ToolWasApprovedTest extends TrajectoryAssertionTestCase
{
    public function test_passes_when_tool_was_approved(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Approved);
        $tool->setResult('refunded');

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function test_fails_when_tool_was_rejected(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Rejected, 'too expensive');

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame("Tool 'refund_order' was called, but no call was approved (approval states: rejected)", $result->message);
    }

    public function test_fails_while_the_decision_is_still_pending(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Pending);

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertFalse($result->passed);
        $this->assertSame("Tool 'refund_order' was called, but no call was approved (approval states: pending)", $result->message);
    }

    public function test_passes_when_any_call_of_the_tool_was_approved(): void
    {
        $rejected = $this->makeTool('refund_order', ['order_id' => '1'], 'call_1');
        $rejected->setApprovalState(ApprovalState::Rejected, 'wrong order');
        $approved = $this->makeTool('refund_order', ['order_id' => '2'], 'call_2');
        $approved->setApprovalState(ApprovalState::Approved);

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($rejected, $approved));

        $this->assertTrue($result->passed);
    }

    public function test_approval_of_another_tool_does_not_count(): void
    {
        $other = $this->makeTool('send_email', ['to' => 'a@b.c']);
        $other->setApprovalState(ApprovalState::Approved);
        $gated = $this->makeTool('refund_order', ['order_id' => '1']);

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($other, $gated));

        $this->assertFalse($result->passed);
        $this->assertSame("Tool 'refund_order' was called, but no call was approved (approval states: not approval-gated)", $result->message);
    }

    public function test_fails_when_tool_was_not_approval_gated(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setResult('refunded');

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertFalse($result->passed);
        $this->assertSame("Tool 'refund_order' was called, but no call was approved (approval states: not approval-gated)", $result->message);
    }

    public function test_fails_when_tool_was_never_called(): void
    {
        $result = (new ToolWasApproved('refund_order'))->evaluate($this->emptyTrajectory());

        $this->assertFalse($result->passed);
        $this->assertSame("Expected tool 'refund_order' to be approved, but it was never called (no tool was called)", $result->message);
    }
}
