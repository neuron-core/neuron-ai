<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\Assertions\Trajectory\Mode;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Tests\Support\TrajectoryAssertionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_filter;

class TrajectoryMatchesNonListExpectedTest extends TrajectoryAssertionTestCase
{
    /**
     * @return iterable<string, array{Mode}>
     */
    public static function modes(): iterable
    {
        foreach (Mode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    #[DataProvider('modes')]
    public function test_expected_names_built_with_array_filter_match_like_a_list(Mode $mode): void
    {
        // array_filter() keeps keys: [1 => 'search', 2 => 'refund_order']
        $expected = array_filter(['', 'search', 'refund_order']);
        $trajectory = $this->trajectoryWithTools(
            $this->makeTool('search', [], 'call_1'),
            $this->makeTool('refund_order', [], 'call_2'),
        );

        $this->assertTrue((new TrajectoryMatches($expected, $mode))->evaluate($trajectory)->passed);
    }
}
