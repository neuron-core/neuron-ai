<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function implode;

/**
 * Passes when at least one call of the given tool carries an approved human
 * decision (HITL — the approval state the framework stamps on tool entries).
 */
class ToolWasApproved extends TrajectoryAssertion
{
    public function __construct(protected string $name)
    {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $calls = $trajectory->toolCalls($this->name);

        if ($calls === []) {
            return AssertionResult::fail(
                0.0,
                "Expected tool '{$this->name}' to be approved, but it was never called ({$this->describeCalls($trajectory)})",
            );
        }

        foreach ($calls as $call) {
            if ($call->getApprovalState() === ApprovalState::Approved) {
                return AssertionResult::pass(1.0);
            }
        }

        return AssertionResult::fail(
            0.0,
            "Tool '{$this->name}' was called, but no call was approved (approval states: "
                . implode(', ', self::approvalStates($calls)) . ')',
        );
    }

    /**
     * @param ToolInterface[] $calls
     * @return string[]
     */
    protected static function approvalStates(array $calls): array
    {
        return array_map(
            static fn (ToolInterface $tool): string => $tool->getApprovalState() instanceof ApprovalState
                ? $tool->getApprovalState()->value
                : 'not approval-gated',
            $calls
        );
    }
}
