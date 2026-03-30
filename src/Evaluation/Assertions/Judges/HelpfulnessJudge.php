<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Judges;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Evaluation\Assertions\AgentJudge;

/**
 * Evaluates if the output is helpful and actionable for the user.
 * Considers clarity, completeness, and practical utility of the response.
 */
class HelpfulnessJudge extends AgentJudge
{
    public function __construct(
        AgentInterface $judge,
        float $threshold = 0.7,
        array $examples = [],
    ) {
        parent::__construct(
            judge: $judge,
            criteria: 'Evaluate how helpful and actionable the response is for the user. A helpful response should: (1) be clear and easy to understand, (2) provide complete information that addresses the user\'s needs, (3) offer practical, actionable guidance when appropriate, (4) be concise without being terse. Consider both the quality and usefulness of the information provided.',
            threshold: $threshold,
            reference: null,
            examples: $examples
        );
    }
}
