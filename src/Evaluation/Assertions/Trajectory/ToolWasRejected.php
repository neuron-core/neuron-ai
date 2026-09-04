<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Trajectory;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;

use function array_map;
use function implode;

/**
 * Passes when at least one call of the given tool carries a rejected human
 * decision (HITL — the approval state the framework stamps on tool entries).
 */
class ToolWasRejected extends TrajectoryAssertion
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
                "Expected tool '{$this->name}' to be rejected, but it was never called ({$this->describeCalls($trajectory)})",
            );
        }

        foreach ($calls as $call) {
            if ($call->getApprovalState() === ApprovalState::Rejected) {
                return AssertionResult::pass(1.0);
            }
        }

        return AssertionResult::fail(
            0.0,
            "Tool '{$this->name}' was called, but no call was rejected (approval states: "
                . implode(', ', self::approvalStates($calls)) . ')',
        );
    }

    /**
     * @param ToolCall[] $calls
     * @return string[]
     */
    protected static function approvalStates(array $calls): array
    {
        return array_map(
            static fn (ToolCall $tool): string => $tool->getApprovalState() instanceof ApprovalState
                ? $tool->getApprovalState()->value
                : 'not approval-gated',
            $calls
        );
    }
}
