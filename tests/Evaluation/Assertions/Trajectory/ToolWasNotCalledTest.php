<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasNotCalled;
use NeuronAI\Tests\Support\TrajectoryAssertionTestCase;

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
        $this->assertStringContainsString("Expected tool 'refund_order' not to be called", $result->message);
        $this->assertStringContainsString('called 1 time(s)', $result->message);
        $this->assertStringContainsString('{"order_id":"123"}', $result->message);
    }
}
