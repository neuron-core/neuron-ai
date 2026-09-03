<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

use NeuronAI\Evaluation\Runner\EvaluationSuiteSummary;

interface EvaluationOutputInterface
{
    public function output(EvaluationSuiteSummary $summary): void;
}
