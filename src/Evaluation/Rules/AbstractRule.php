<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;

abstract class AbstractRule implements EvaluationRuleInterface
{
    public function getName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }
}
