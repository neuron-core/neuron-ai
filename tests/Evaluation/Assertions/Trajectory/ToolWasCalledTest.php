<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;

class ToolWasCalledTest extends TrajectoryAssertionTestCase
{
    public function testPassesWhenToolWasCalled(): void
    {
        $trajectory = $this->trajectoryWithTools($this->makeTool('search', ['q' => 'x']));

        $result = (new ToolWasCalled('search'))->evaluate($trajectory);

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function testFailsWhenToolWasNeverCalled(): void
    {
        $trajectory = $this->trajectoryWithTools($this->makeTool('search', ['q' => 'x']));

        $result = (new ToolWasCalled('refund_order'))->evaluate($trajectory);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString("Expected tool 'refund_order' to be called", $result->message);
        $this->assertStringContainsString('search({"q":"x"})', $result->message);
    }

    public function testFailsOnEmptyTrajectory(): void
    {
        $result = (new ToolWasCalled('search'))->evaluate($this->emptyTrajectory());

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('no tool was called', $result->message);
    }

    public function testArgumentSubsetMatchAllowsExtraInputs(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '123', 'notify' => true])
        );

        $result = (new ToolWasCalled('refund_order', ['order_id' => '123']))->evaluate($trajectory);

        $this->assertTrue($result->passed);
    }

    public function testArgumentMismatchFails(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '999'])
        );

        $result = (new ToolWasCalled('refund_order', ['order_id' => '123']))->evaluate($trajectory);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('no call matched the argument constraint', $result->message);
        $this->assertStringContainsString('{"order_id":"999"}', $result->message);
    }

    public function testAnyMatchingCallPasses(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('search', ['q' => 'wrong'], 'call_1'),
            $this->makeTool('search', ['q' => 'right'], 'call_2'),
        );

        $result = (new ToolWasCalled('search', ['q' => 'right']))->evaluate($trajectory);

        $this->assertTrue($result->passed);
    }

    public function testClosureConstraint(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['amount' => 150])
        );

        $over = new ToolWasCalled('refund_order', fn (array $inputs): bool => $inputs['amount'] > 100);
        $under = new ToolWasCalled('refund_order', fn (array $inputs): bool => $inputs['amount'] < 100);

        $this->assertTrue($over->evaluate($trajectory)->passed);
        $this->assertFalse($under->evaluate($trajectory)->passed);
    }

    public function testThrowsOnNonTrajectoryInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('evaluates a Trajectory, got string');

        (new ToolWasCalled('search'))->evaluate('a plain string');
    }

    public function testGetName(): void
    {
        $this->assertSame('ToolWasCalled', (new ToolWasCalled('search'))->getName());
    }
}
