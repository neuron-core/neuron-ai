<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Judges;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Evaluation\Assertions\AgentJudge;

/**
 * Evaluates semantic correctness by comparing the actual output to an expected reference.
 * Focuses on meaning and factual accuracy rather than exact string matching.
 */
class CorrectnessJudge extends AgentJudge
{
    public function __construct(
        AgentInterface $judge,
        string $expected,
        float $threshold = 0.7,
        array $examples = [],
    ) {
        parent::__construct(
            judge: $judge,
            criteria: 'Evaluate if the actual output conveys the same meaning and facts as the expected output. Consider semantic equivalence - different wording that expresses the same meaning should score highly. Penalize factual errors, missing key information, or contradictions.',
            threshold: $threshold,
            reference: $expected,
            examples: $examples
        );
    }
}
