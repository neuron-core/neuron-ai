<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasApproved;
use NeuronAI\Tools\ApprovalState;

class ToolWasApprovedTest extends TrajectoryAssertionTestCase
{
    public function testPassesWhenToolWasApproved(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Approved);
        $tool->setResult('refunded');

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function testFailsWhenToolWasRejected(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Rejected, 'too expensive');

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('no call was approved', $result->message);
        $this->assertStringContainsString('rejected', $result->message);
    }

    public function testFailsWhenToolWasNotApprovalGated(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setResult('refunded');

        $result = (new ToolWasApproved('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('not approval-gated', $result->message);
    }

    public function testFailsWhenToolWasNeverCalled(): void
    {
        $result = (new ToolWasApproved('refund_order'))->evaluate($this->emptyTrajectory());

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('never called', $result->message);
    }
}
