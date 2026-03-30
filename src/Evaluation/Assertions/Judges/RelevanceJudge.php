<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Judges;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Evaluation\Assertions\AgentJudge;

/**
 * Evaluates if the output directly addresses and answers the given question or input.
 * Penalizes responses that are off-topic, evasive, or fail to address the core question.
 */
class RelevanceJudge extends AgentJudge
{
    public function __construct(
        AgentInterface $judge,
        string $question,
        float $threshold = 0.7,
        array $examples = [],
    ) {
        parent::__construct(
            judge: $judge,
            criteria: 'Evaluate if the response directly addresses and answers the given question. A relevant response should: (1) understand the core of what is being asked, (2) provide information that helps answer the question, (3) stay on topic without unnecessary tangents. Penalize evasive, off-topic, or unresponsive answers.',
            threshold: $threshold,
            reference: "Original question: {$question}",
            examples: $examples
        );
    }
}
