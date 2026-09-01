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
        $this->assertStringContainsString('no call was rejected', $result->message);
        $this->assertStringContainsString('approved', $result->message);
    }

    public function test_fails_when_tool_was_never_called(): void
    {
        $result = (new ToolWasRejected('refund_order'))->evaluate($this->emptyTrajectory());

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('never called', $result->message);
    }
}
