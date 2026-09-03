<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

class EvaluationSuiteSummary
{
    /**
     * @param array<EvaluatorReport> $evaluatorReports
     */
    public function __construct(
        protected readonly array $evaluatorReports,
    ) {
    }

    /**
     * @return array<EvaluatorReport>
     */
    public function getEvaluatorReports(): array
    {
        return $this->evaluatorReports;
    }

    public function getAggregateSummary(): EvaluatorSummary
    {
        $results = [];
        $totalExecutionTime = 0.0;

        foreach ($this->evaluatorReports as $report) {
            foreach ($report->getSummary()->getResults() as $result) {
                $results[] = $result;
            }

            $totalExecutionTime += $report->getSummary()->getTotalExecutionTime();
        }

        return new EvaluatorSummary($results, $totalExecutionTime);
    }

    public function hasFailures(): bool
    {
        foreach ($this->evaluatorReports as $report) {
            if ($report->hasFailures()) {
                return true;
            }
        }

        return false;
    }
}
