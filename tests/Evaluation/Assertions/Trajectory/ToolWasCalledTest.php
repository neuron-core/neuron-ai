<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use InvalidArgumentException;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Tests\Support\TrajectoryAssertionTestCase;

class ToolWasCalledTest extends TrajectoryAssertionTestCase
{
    public function test_passes_when_tool_was_called(): void
    {
        $trajectory = $this->trajectoryWithTools($this->makeTool('search', ['q' => 'x']));

        $result = (new ToolWasCalled('search'))->evaluate($trajectory);

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function test_fails_when_tool_was_never_called(): void
    {
        $trajectory = $this->trajectoryWithTools($this->makeTool('search', ['q' => 'x']));

        $result = (new ToolWasCalled('refund_order'))->evaluate($trajectory);

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame("Expected tool 'refund_order' to be called, but it was not (called: search({\"q\":\"x\"}))", $result->message);
    }

    public function test_fails_on_empty_trajectory(): void
    {
        $result = (new ToolWasCalled('search'))->evaluate($this->emptyTrajectory());

        $this->assertFalse($result->passed);
        $this->assertSame("Expected tool 'search' to be called, but it was not (no tool was called)", $result->message);
    }

    public function test_argument_subset_match_allows_extra_inputs(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '123', 'notify' => true])
        );

        $result = (new ToolWasCalled('refund_order', ['order_id' => '123']))->evaluate($trajectory);

        $this->assertTrue($result->passed);
    }

    public function test_argument_mismatch_fails(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '999'])
        );

        $result = (new ToolWasCalled('refund_order', ['order_id' => '123']))->evaluate($trajectory);

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame(
            "Tool 'refund_order' was called 1 time(s), but no call matched the argument constraint (arguments seen: {\"order_id\":\"999\"})",
            $result->message
        );
    }

    public function test_argument_values_are_compared_strictly(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '123', 'amount' => 10.0, 'notify' => 1])
        );

        $this->assertFalse((new ToolWasCalled('refund_order', ['order_id' => 123]))->evaluate($trajectory)->passed);
        $this->assertFalse((new ToolWasCalled('refund_order', ['amount' => 10]))->evaluate($trajectory)->passed);
        $this->assertFalse((new ToolWasCalled('refund_order', ['notify' => true]))->evaluate($trajectory)->passed);
        $this->assertTrue((new ToolWasCalled('refund_order', ['order_id' => '123', 'amount' => 10.0]))->evaluate($trajectory)->passed);
    }

    public function test_a_constrained_key_must_be_present_even_when_expecting_null(): void
    {
        $withNull = $this->trajectoryWithTools($this->makeTool('refund_order', ['coupon' => null], 'call_1'));
        $withoutKey = $this->trajectoryWithTools($this->makeTool('refund_order', [], 'call_1'));
        $assertion = new ToolWasCalled('refund_order', ['coupon' => null]);

        $this->assertTrue($assertion->evaluate($withNull)->passed);
        $this->assertFalse($assertion->evaluate($withoutKey)->passed);
    }

    public function test_nested_argument_values_must_match_exactly(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('ship', ['items' => ['a', 'b'], 'address' => ['city' => 'Rome', 'zip' => '00100']])
        );

        $this->assertTrue((new ToolWasCalled('ship', ['items' => ['a', 'b']]))->evaluate($trajectory)->passed);
        $this->assertFalse((new ToolWasCalled('ship', ['items' => ['b', 'a']]))->evaluate($trajectory)->passed);
        // No deep subset matching: a nested array is compared as a whole
        $this->assertFalse((new ToolWasCalled('ship', ['address' => ['city' => 'Rome']]))->evaluate($trajectory)->passed);
    }

    public function test_empty_argument_constraint_matches_any_call(): void
    {
        $trajectory = $this->trajectoryWithTools($this->makeTool('search', ['q' => 'x']));

        $this->assertTrue((new ToolWasCalled('search', []))->evaluate($trajectory)->passed);
    }

    public function test_failure_lists_the_arguments_of_every_call_of_the_tool(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['order_id' => '1'], 'call_1'),
            $this->makeTool('search', ['q' => 'x'], 'call_2'),
            $this->makeTool('refund_order', ['order_id' => '2'], 'call_3'),
        );

        $result = (new ToolWasCalled('refund_order', ['order_id' => '3']))->evaluate($trajectory);

        $this->assertSame(
            "Tool 'refund_order' was called 2 time(s), but no call matched the argument constraint (arguments seen: {\"order_id\":\"1\"}, {\"order_id\":\"2\"})",
            $result->message
        );
    }

    public function test_any_matching_call_passes(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('search', ['q' => 'wrong'], 'call_1'),
            $this->makeTool('search', ['q' => 'right'], 'call_2'),
        );

        $result = (new ToolWasCalled('search', ['q' => 'right']))->evaluate($trajectory);

        $this->assertTrue($result->passed);
    }

    public function test_closure_constraint(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('refund_order', ['amount' => 150])
        );

        $received = [];
        $over = new ToolWasCalled('refund_order', function (array $inputs) use (&$received): bool {
            $received[] = $inputs;
            return $inputs['amount'] > 100;
        });
        $under = new ToolWasCalled('refund_order', fn (array $inputs): bool => $inputs['amount'] < 100);

        $this->assertTrue($over->evaluate($trajectory)->passed);
        $this->assertFalse($under->evaluate($trajectory)->passed);
        $this->assertSame([['amount' => 150]], $received);
    }

    public function test_closure_constraint_is_only_asked_about_calls_of_the_named_tool(): void
    {
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('search', ['q' => 'x'], 'call_1'),
            $this->makeTool('refund_order', ['amount' => 5], 'call_2'),
        );
        $seen = [];

        (new ToolWasCalled('refund_order', function (array $inputs) use (&$seen): bool {
            $seen[] = $inputs;
            return false;
        }))->evaluate($trajectory);

        $this->assertSame([['amount' => 5]], $seen);
    }

    public function test_throws_on_non_trajectory_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ToolWasCalled::class . ' evaluates a Trajectory, got string');

        (new ToolWasCalled('search'))->evaluate('a plain string');
    }

    public function test_get_name(): void
    {
        $this->assertSame('ToolWasCalled', (new ToolWasCalled('search'))->getName());
    }
}
