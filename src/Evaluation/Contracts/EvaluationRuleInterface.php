<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

use NeuronAI\Evaluation\EvaluationRuleResult;

interface EvaluationRuleInterface
{
    /**
     * Evaluate the given input against expected criteria
     */
    public function evaluate(mixed $actual): EvaluationRuleResult;

    /**
     * Get the name of this evaluation rule
     */
    public function getName(): string;
}
