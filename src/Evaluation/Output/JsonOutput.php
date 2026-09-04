<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Output;

use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Score;
use RuntimeException;
use JsonException;

use function array_map;
use function file_put_contents;
use function is_array;
use function is_bool;
use function is_object;
use function is_string;
use function json_encode;
use function is_float;
use function is_int;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

class JsonOutput implements EvaluationOutputInterface
{
    public function __construct(
        private readonly ?string $path = null
    ) {
    }

    public function output(EvaluationReport $report): void
    {
        $data = $this->evaluationReportToArray($report);

        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode summary to JSON: ' . $e->getMessage(), 0, $e);
        }

        if ($this->path !== null) {
            $result = @file_put_contents($this->path, $json);
            if ($result === false) {
                throw new RuntimeException("Failed to write to file: {$this->path}");
            }
        } else {
            echo $json;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function evaluationReportToArray(EvaluationReport $report): array
    {
        return [
            ...$this->resultsToArray($report->getResults()),
            'started_at' => $report->getStartedAt()->format("Y-m-d\\TH:i:s.uP"),
            'finished_at' => $report->getFinishedAt()->format("Y-m-d\\TH:i:s.uP"),
            'duration' => $report->getDuration(),
            'has_failures' => $report->hasFailures(),
            'evaluators' => array_map(
                $this->evaluatorReportToArray(...),
                $report->getEvaluatorReports(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function evaluatorReportToArray(EvaluatorReport $report): array
    {
        $data = $this->resultsToArray($report->getResults());
        unset($data['results']);

        return [
            'evaluator_class' => $report->getEvaluatorClass(),
            'namespace' => $report->getNamespace(),
            'started_at' => $report->getStartedAt()->format("Y-m-d\\TH:i:s.uP"),
            'finished_at' => $report->getFinishedAt()->format("Y-m-d\\TH:i:s.uP"),
            'duration' => $report->getDuration(),
            ...$data,
            'error' => $report->getError(),
            'has_failures' => $report->hasFailures(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultsToArray(EvaluationResults $results): array
    {
        $allScores = $results->getAllAssertionScores();

        return [
            'total' => $results->getTotalCount(),
            'passed' => $results->getPassedCount(),
            'failed' => $results->getFailedCount(),
            'success_rate' => $results->getSuccessRate(),
            'average_execution_time' => $results->getAverageExecutionTime(),
            'total_assertions' => $results->getTotalAssertions(),
            'assertions_passed' => $results->getTotalAssertionsPassed(),
            'assertions_failed' => $results->getTotalAssertionsFailed(),
            'assertion_success_rate' => $results->getAssertionSuccessRate(),
            'cached_runs' => $results->getCachedRunCount(),
            'score_statistics' => $allScores !== [] ? [
                'average_score' => $results->getAverageAssertionScore(),
                'min_score' => $results->getMinAssertionScore(),
                'max_score' => $results->getMaxAssertionScore(),
            ] : null,
            'metrics' => $allScores !== [] ? $results->getScoreStatisticsByLabel() : null,
            'results' => array_map(
                fn (EvaluatorResult $r): array => [
                    'evaluator_class' => $r->getEvaluatorClass(),
                    'index' => $r->getIndex(),
                    'passed' => $r->isPassed(),
                    'input' => $r->getInput(),
                    'output' => $this->formatOutput($r->getOutput()),
                    'execution_time' => $r->getExecutionTime(),
                    'cached_run' => $r->isCachedRun(),
                    'error' => $r->getError(),
                    'assertions_passed' => $r->getAssertionsPassed(),
                    'assertions_failed' => $r->getAssertionsFailed(),
                    'assertion_scores' => $r->getAssertionScores(),
                    'scores' => array_map(
                        fn (Score $s): array => [
                            'label' => $s->label,
                            'value' => $s->value,
                            'passed' => $s->passed,
                        ],
                        $r->getScoreRecords()
                    ),
                ],
                $results->getResults()
            ),
        ];
    }

    private function formatOutput(mixed $output): mixed
    {
        if (is_string($output) || is_int($output) || is_float($output) || is_bool($output) || $output === null) {
            return $output;
        }

        if (is_array($output) || is_object($output)) {
            try {
                return json_encode($output, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 'Unable to serialize output';
            }
        }

        return (string) $output;
    }
}
