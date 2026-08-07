<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Tools\ToolCall;

use function array_map;
use function count;
use function implode;
use function json_encode;

/**
 * Passes when the trajectory contains no call of the given tool — guardrail
 * scenarios ("the agent must NOT refund without asking").
 */
class ToolWasNotCalled extends TrajectoryAssertion
{
    public function __construct(protected string $name)
    {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $calls = $trajectory->toolCalls($this->name);

        if ($calls === []) {
            return AssertionResult::pass(1.0);
        }

        $arguments = implode(', ', array_map(
            static fn (ToolCall $tool): string => (string) json_encode($tool->getInputs()),
            $calls
        ));

        return AssertionResult::fail(
            0.0,
            "Expected tool '{$this->name}' not to be called, but it was called "
                . count($calls) . " time(s) (arguments: {$arguments})",
        );
    }
}
