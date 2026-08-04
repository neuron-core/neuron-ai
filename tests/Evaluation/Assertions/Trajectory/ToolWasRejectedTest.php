<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasRejected;
use NeuronAI\Tools\ApprovalState;

class ToolWasRejectedTest extends TrajectoryAssertionTestCase
{
    public function testPassesWhenToolWasRejected(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Rejected, 'amount too high');
        $tool->setResult('rejected by the user');

        $result = (new ToolWasRejected('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function testFailsWhenToolWasApproved(): void
    {
        $tool = $this->makeTool('refund_order', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Approved);
        $tool->setResult('refunded');

        $result = (new ToolWasRejected('refund_order'))->evaluate($this->trajectoryWithTools($tool));

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('no call was rejected', $result->message);
        $this->assertStringContainsString('approved', $result->message);
    }

    public function testFailsWhenToolWasNeverCalled(): void
    {
        $result = (new ToolWasRejected('refund_order'))->evaluate($this->emptyTrajectory());

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('never called', $result->message);
    }
}
