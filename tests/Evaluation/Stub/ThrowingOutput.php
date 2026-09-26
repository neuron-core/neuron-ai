<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use RuntimeException;

/**
 * Test output driver that always fails
 */
class ThrowingOutput implements EvaluationOutputInterface
{
    public function output(EvaluationReport $report): void
    {
        throw new RuntimeException('disk full');
    }
}
