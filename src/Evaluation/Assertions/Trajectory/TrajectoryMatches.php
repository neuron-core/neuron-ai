<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Tools\ToolInterface;

use function array_diff;
use function array_map;
use function count;
use function implode;
use function sort;

/**
 * Matches the sequence of tool-call names against an expected trajectory,
 * with {@see Mode} deciding the semantics. Names only — argument precision
 * belongs to {@see ToolWasCalled}.
 */
class TrajectoryMatches extends TrajectoryAssertion
{
    /**
     * @param string[] $expected Expected tool names, in order.
     */
    public function __construct(
        protected array $expected,
        protected Mode $mode = Mode::Strict,
    ) {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $actual = array_map(
            static fn (ToolInterface $tool): string => $tool->getName(),
            $trajectory->toolCalls()
        );

        $matched = match ($this->mode) {
            Mode::Strict => $actual === $this->expected,
            Mode::Unordered => $this->sameMultiset($actual),
            Mode::Subset => $this->isSubsequence($actual),
            Mode::Superset => array_diff($actual, $this->expected) === [],
        };

        if ($matched) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            'Expected trajectory [' . implode(', ', $this->expected) . "] ({$this->mode->value} mode), "
                . 'actual was [' . implode(', ', $actual) . ']',
        );
    }

    /**
     * @param string[] $actual
     */
    protected function sameMultiset(array $actual): bool
    {
        $expected = $this->expected;
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /**
     * Whether the expected names appear in order within the actual sequence
     * (extra calls allowed anywhere).
     *
     * @param string[] $actual
     */
    protected function isSubsequence(array $actual): bool
    {
        $position = 0;
        foreach ($actual as $name) {
            if ($position < count($this->expected) && $name === $this->expected[$position]) {
                $position++;
            }
        }

        return $position === count($this->expected);
    }
}
