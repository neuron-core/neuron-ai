<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Judges;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Evaluation\Assertions\AgentJudge;

/**
 * Evaluates if the output is factually grounded in the provided context.
 * Penalizes hallucinations and information not present in the context.
 */
class FaithfulnessJudge extends AgentJudge
{
    public function __construct(
        AgentInterface $judge,
        string $context,
        float $threshold = 0.7,
        array $examples = [],
    ) {
        parent::__construct(
            judge: $judge,
            criteria: 'The response must be factually grounded in the provided context. Penalize any claims or information not supported by the context (hallucinations). A faithful response contains only information that can be verified from the context.',
            threshold: $threshold,
            reference: "Context:\n{$context}",
            examples: $examples
        );
    }
}
