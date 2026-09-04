<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use DateTimeImmutable;

class EvaluationReport
{
    /**
     * @param array<EvaluatorReport> $evaluatorReports
     */
    public function __construct(
        protected readonly array $evaluatorReports,
        protected readonly DateTimeImmutable $startedAt,
        protected readonly DateTimeImmutable $finishedAt,
    ) {
    }

    /**
     * @return array<EvaluatorReport>
     */
    public function getEvaluatorReports(): array
    {
        return $this->evaluatorReports;
    }

    public function getResults(): EvaluationResults
    {
        $results = [];

        foreach ($this->evaluatorReports as $report) {
            foreach ($report->getResults()->getResults() as $result) {
                $results[] = $result;
            }
        }

        return new EvaluationResults($results);
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDuration(): float
    {
        return (float) $this->finishedAt->format("U.u") - (float) $this->startedAt->format("U.u");
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
