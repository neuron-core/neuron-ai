<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

use NeuronAI\Evaluation\Runner\EvaluationReport;

interface EvaluationOutputInterface
{
    public function output(EvaluationReport $report): void;
}
