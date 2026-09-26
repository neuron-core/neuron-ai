<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasNotCalled;
use NeuronAI\Tests\Support\TrajectoryAssertionTestCase;
use NeuronAI\Tools\ApprovalState;

class ToolWasNotCalledTest extends TrajectoryAssertionTestCase
{
    public function test_passes_when_tool_was_never_called(): void
    {
        $trajectory = $this->trajectoryWithTools($this->makeTool('search', ['q' => 'x']));

        $result = (new ToolWasNotCalled('refund_order'))->evaluate($trajectory);

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function test_passes_on_empty_trajectory(): void
    {
        $result = (new ToolWasNotCalled('refund_order'))->evaluate($this->emptyTrajectory());

        $this->assertTrue($result->passed);
    }

    public function test_fails_when_tool_was_called(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '123'])
        );

        $result = (new ToolWasNotCalled('refund_order'))->evaluate($trajectory);

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame(
            "Expected tool 'refund_order' not to be called, but it was called 1 time(s) (arguments: {\"order_id\":\"123\"})",
            $result->message
        );
    }

    public function test_failure_lists_the_arguments_of_every_call(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '1'], 'call_1'),
            $this->makeTool('search', ['q' => 'x'], 'call_2'),
            $this->makeTool('refund_order', ['order_id' => '2'], 'call_3'),
        );

        $result = (new ToolWasNotCalled('refund_order'))->evaluate($trajectory);

        $this->assertSame(
            "Expected tool 'refund_order' not to be called, but it was called 2 time(s) (arguments: {\"order_id\":\"1\"}, {\"order_id\":\"2\"})",
            $result->message
        );
    }

    public function test_tool_names_match_exactly(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order_preview', [], 'call_1'),
            $this->makeTool('Refund_Order', [], 'call_2'),
        );

        $this->assertTrue((new ToolWasNotCalled('refund_order'))->evaluate($trajectory)->passed);
    }

    public function test_a_call_rejected_by_the_human_still_counts_as_called(): void
    {
        // The guardrail is about what the agent attempted, not what executed
        $tool = $this->makeTool('refund_order', ['order_id' => '1']);
        $tool->setApprovalState(ApprovalState::Rejected, 'not allowed');

        $this->assertFalse((new ToolWasNotCalled('refund_order'))->evaluate($this->trajectoryWithTools($tool))->passed);
    }
}
