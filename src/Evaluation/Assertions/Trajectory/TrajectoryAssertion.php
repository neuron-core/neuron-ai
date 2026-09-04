<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Trajectory;

use InvalidArgumentException;
use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;

use function array_map;
use function get_debug_type;
use function implode;
use function json_encode;

/**
 * Base for assertions that evaluate a Trajectory.
 */
abstract class TrajectoryAssertion extends AbstractAssertion
{
    /**
     * The interface's `mixed` cannot be narrowed to `Trajectory` (parameter
     * types are contravariant), so the type is enforced here: anything else is
     * a coding error in the evaluator — an exception the runner records as an
     * item error — not a failed assertion about the agent.
     */
    final public function evaluate(mixed $actual): AssertionResult
    {
        if (!$actual instanceof Trajectory) {
            throw new InvalidArgumentException(
                static::class . ' evaluates a Trajectory, got ' . get_debug_type($actual)
            );
        }

        return $this->evaluateTrajectory($actual);
    }

    abstract protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult;

    /**
     * Render the calls that actually happened — failure messages are debugging
     * surfaces, so always say what the trajectory DID contain.
     */
    protected function describeCalls(Trajectory $trajectory): string
    {
        $calls = $trajectory->toolCalls();

        if ($calls === []) {
            return 'no tool was called';
        }

        return 'called: ' . implode(', ', array_map(
            static fn (ToolCall $tool): string => $tool->getName() . '(' . json_encode($tool->getInputs()) . ')',
            $calls
        ));
    }
}
