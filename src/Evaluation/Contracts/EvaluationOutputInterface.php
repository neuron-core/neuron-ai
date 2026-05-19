<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

use NeuronAI\Evaluation\Runner\EvaluatorSummary;

interface EvaluationOutputInterface
{
    public function output(EvaluatorSummary $summary): void;
}
