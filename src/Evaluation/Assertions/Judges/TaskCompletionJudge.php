<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Judges;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Evaluation\Assertions\AgentJudge;

/**
 * Evaluates whether the assistant accomplished the user's goal over the whole
 * conversation. Feed it a Trajectory: the judge reads the full transcript —
 * tool calls with their results, human approval decisions, and the final
 * answer — not just the last message.
 */
class TaskCompletionJudge extends AgentJudge
{
    public function __construct(
        AgentInterface $judge,
        string $goal,
        float $threshold = 0.7,
        array $examples = [],
    ) {
        parent::__construct(
            judge: $judge,
            criteria: 'Evaluate whether the assistant accomplished the user\'s goal over the course of the conversation. Consider the whole trajectory: the tools invoked and their results, any human approval decisions, and the final answer. A goal blocked by a human rejection should be judged on how the assistant handled the rejection and what remained achievable. Score 1.0 for a fully accomplished goal, partial scores for partial completion, and low scores when the goal was missed or the assistant gave up prematurely.',
            threshold: $threshold,
            reference: "User goal:\n{$goal}",
            examples: $examples
        );
    }
}
