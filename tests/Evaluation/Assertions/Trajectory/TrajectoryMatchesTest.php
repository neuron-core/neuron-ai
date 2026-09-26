<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tests\Support\TrajectoryAssertionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TrajectoryMatchesTest extends TrajectoryAssertionTestCase
{
    protected function fixture(): Trajectory
    {
        // Actual call sequence: search, refund_order, send_email
        return $this->trajectoryWithTools(
            $this->makeTool('search', [], 'call_1'),
            $this->makeTool('refund_order', [], 'call_2'),
            $this->makeTool('send_email', [], 'call_3'),
        );
    }

    public function test_strict_mode(): void
    {
        $trajectory = $this->fixture();

        $exact = new TrajectoryMatches(['search', 'refund_order', 'send_email'], Mode::Strict);
        $wrongOrder = new TrajectoryMatches(['refund_order', 'search', 'send_email'], Mode::Strict);
        $missing = new TrajectoryMatches(['search', 'refund_order'], Mode::Strict);

        $this->assertTrue($exact->evaluate($trajectory)->passed);
        $this->assertFalse($wrongOrder->evaluate($trajectory)->passed);
        $this->assertFalse($missing->evaluate($trajectory)->passed);
    }

    public function test_unordered_mode(): void
    {
        $trajectory = $this->fixture();

        $shuffled = new TrajectoryMatches(['send_email', 'search', 'refund_order'], Mode::Unordered);
        $differentSet = new TrajectoryMatches(['send_email', 'search', 'other_tool'], Mode::Unordered);
        $fewer = new TrajectoryMatches(['search', 'refund_order'], Mode::Unordered);

        $this->assertTrue($shuffled->evaluate($trajectory)->passed);
        $this->assertFalse($differentSet->evaluate($trajectory)->passed);
        $this->assertFalse($fewer->evaluate($trajectory)->passed);
    }

    public function test_subset_mode(): void
    {
        $trajectory = $this->fixture();

        $inOrderWithGap = new TrajectoryMatches(['search', 'send_email'], Mode::Subset);
        $outOfOrder = new TrajectoryMatches(['send_email', 'search'], Mode::Subset);
        $notPresent = new TrajectoryMatches(['search', 'other_tool'], Mode::Subset);

        $this->assertTrue($inOrderWithGap->evaluate($trajectory)->passed);
        $this->assertFalse($outOfOrder->evaluate($trajectory)->passed);
        $this->assertFalse($notPresent->evaluate($trajectory)->passed);
    }

    public function test_superset_mode(): void
    {
        $trajectory = $this->fixture();

        $allowsAll = new TrajectoryMatches(['search', 'refund_order', 'send_email', 'unused_tool'], Mode::Superset);
        $forbidsOne = new TrajectoryMatches(['search', 'refund_order'], Mode::Superset);

        $this->assertTrue($allowsAll->evaluate($trajectory)->passed);
        $this->assertFalse($forbidsOne->evaluate($trajectory)->passed);
    }

    public function test_strict_is_the_default_mode(): void
    {
        $trajectory = $this->fixture();

        $result = (new TrajectoryMatches(['search', 'refund_order', 'send_email']))->evaluate($trajectory);

        $this->assertTrue($result->passed);
    }

    public function test_empty_expected_trajectory(): void
    {
        $empty = $this->emptyTrajectory();
        $withCalls = $this->fixture();

        // No calls expected, none happened — passes in every mode.
        $this->assertTrue((new TrajectoryMatches([], Mode::Strict))->evaluate($empty)->passed);
        // Subset of anything is trivially satisfied by an empty expectation.
        $this->assertTrue((new TrajectoryMatches([], Mode::Subset))->evaluate($withCalls)->passed);
        // Superset with an empty allowed set forbids every call.
        $this->assertFalse((new TrajectoryMatches([], Mode::Superset))->evaluate($withCalls)->passed);
    }

    public function test_failure_message_names_expected_actual_and_mode(): void
    {
        $result = (new TrajectoryMatches(['a', 'b'], Mode::Strict))->evaluate($this->fixture());

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame('Expected trajectory [a, b] (strict mode), actual was [search, refund_order, send_email]', $result->message);
    }

    /**
     * @return iterable<string, array{Mode, bool}>
     */
    public static function modesAgainstNoCalls(): iterable
    {
        yield 'strict' => [Mode::Strict, false];
        yield 'unordered' => [Mode::Unordered, false];
        yield 'subset' => [Mode::Subset, false];
        yield 'superset' => [Mode::Superset, true];
    }

    #[DataProvider('modesAgainstNoCalls')]
    public function test_expected_calls_against_a_trajectory_without_calls(Mode $mode, bool $passed): void
    {
        $result = (new TrajectoryMatches(['search'], $mode))->evaluate($this->emptyTrajectory());

        $this->assertSame($passed, $result->passed);
    }

    public function test_strict_mode_counts_repeated_calls(): void
    {
        $twice = $this->namedTrajectory('search', 'search');

        $this->assertTrue((new TrajectoryMatches(['search', 'search'], Mode::Strict))->evaluate($twice)->passed);
        $this->assertFalse((new TrajectoryMatches(['search'], Mode::Strict))->evaluate($twice)->passed);
    }

    public function test_unordered_mode_compares_multisets(): void
    {
        $trajectory = $this->namedTrajectory('a', 'a', 'b');

        $this->assertTrue((new TrajectoryMatches(['b', 'a', 'a'], Mode::Unordered))->evaluate($trajectory)->passed);
        $this->assertFalse((new TrajectoryMatches(['a', 'b', 'b'], Mode::Unordered))->evaluate($trajectory)->passed);
        $this->assertFalse((new TrajectoryMatches(['a', 'b'], Mode::Unordered))->evaluate($trajectory)->passed);
    }

    public function test_subset_mode_requires_each_repetition_in_order(): void
    {
        $trajectory = $this->namedTrajectory('search', 'refund_order', 'search');

        $this->assertTrue((new TrajectoryMatches(['search', 'search'], Mode::Subset))->evaluate($trajectory)->passed);
        $this->assertTrue((new TrajectoryMatches(['refund_order', 'search'], Mode::Subset))->evaluate($trajectory)->passed);
        $this->assertFalse((new TrajectoryMatches(['search', 'search', 'search'], Mode::Subset))->evaluate($trajectory)->passed);
        $this->assertFalse((new TrajectoryMatches(['refund_order', 'refund_order'], Mode::Subset))->evaluate($trajectory)->passed);
    }

    public function test_superset_mode_ignores_repetitions_and_order(): void
    {
        $trajectory = $this->namedTrajectory('search', 'search', 'refund_order');

        $this->assertTrue((new TrajectoryMatches(['refund_order', 'search'], Mode::Superset))->evaluate($trajectory)->passed);
    }

    public function test_names_are_compared_case_sensitively(): void
    {
        $trajectory = $this->namedTrajectory('Search');

        foreach (Mode::cases() as $mode) {
            $this->assertFalse((new TrajectoryMatches(['search'], $mode))->evaluate($trajectory)->passed, $mode->value);
        }
    }

    protected function namedTrajectory(string ...$names): Trajectory
    {
        $tools = [];
        foreach ($names as $position => $name) {
            $tools[] = $this->makeTool($name, [], "call_{$position}");
        }

        return $this->trajectoryWithTools(...$tools);
    }
}
