<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use ArrayObject;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluationReport;

/**
 * Test output driver with a constructor dependency, which only a resolver can build
 */
class RecordingOutput implements EvaluationOutputInterface
{
    /**
     * @param ArrayObject<int, EvaluationReport> $reports
     */
    public function __construct(protected ArrayObject $reports)
    {
    }

    public function output(EvaluationReport $report): void
    {
        $this->reports->append($report);
    }
}
