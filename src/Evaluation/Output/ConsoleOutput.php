<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Output;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluationReport;

use function array_count_values;
use function array_map;
use function array_unique;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_object;
use function is_string;
use function json_encode;
use function round;
use function sprintf;
use function str_repeat;

use const JSON_PRETTY_PRINT;

class ConsoleOutput implements EvaluationOutputInterface
{
    public function __construct(
        protected readonly bool $verbose = false
    ) {
    }

    public function output(EvaluationReport $report): void
    {
        $this->printHeader();
        $this->printSummary($report);
    }

    public function printHeader(): void
    {
        echo "Neuron AI Evaluation Runner\n\n";
    }

    public function printProgressSymbol(bool $passed): void
    {
        if (!$this->verbose) {
            echo $passed ? '.' : 'F';
        }
    }

    public function printSummary(EvaluationReport $suite): void
    {
        $results = $suite->getResults();
        $evaluatorLabels = $this->getEvaluatorLabels($suite);

        if (!$this->verbose) {
            echo "\n\n";
        }

        $totalTime = round($suite->getDuration(), 3);
        $avgTime = round($results->getAverageExecutionTime(), 3);

        $this->printEvaluatorErrors($suite, $evaluatorLabels);
        if ($results->hasFailures()) {
            $this->printFailures($results, $evaluatorLabels);
        }

        $this->printAssertionFailureSummary($results, $evaluatorLabels);

        echo sprintf(
            "Started: %s\nFinished: %s\nDuration: %s seconds, Average: %s seconds per test\n\n",
            $suite->getStartedAt()->format("Y-m-d\\TH:i:s.uP"),
            $suite->getFinishedAt()->format("Y-m-d\\TH:i:s.uP"),
            $totalTime,
            $avgTime
        );

        if ($suite->hasFailures()) {
            echo "FAILURES!\n";
        } else {
            echo "OK\n";
        }

        $this->printTotals($results);

        if (count($suite->getEvaluatorReports()) > 1) {
            $this->printEvaluatorBreakdown($suite, $evaluatorLabels);
        }
    }

    /**
     * @param array<string, string> $evaluatorLabels
     */
    protected function printEvaluatorErrors(EvaluationReport $suite, array $evaluatorLabels): void
    {
        $errors = [];
        foreach ($suite->getEvaluatorReports() as $report) {
            if ($report->hasError()) {
                $errors[] = $report;
            }
        }

        if ($errors === []) {
            return;
        }

        echo "There were " . count($errors) . " evaluator error(s):\n\n";

        foreach ($errors as $index => $report) {
            echo ($index + 1) . ") {$evaluatorLabels[$report->getEvaluatorClass()]}\n";
            echo "   Error: {$report->getError()}\n\n";
        }
    }

    /**
     * @param array<string, string> $evaluatorLabels
     */
    protected function printEvaluatorBreakdown(EvaluationReport $suite, array $evaluatorLabels): void
    {
        echo "\nBy evaluator:\n";

        foreach ($suite->getEvaluatorReports() as $report) {
            echo "  {$evaluatorLabels[$report->getEvaluatorClass()]}\n";
            echo "    Duration: " . round($report->getDuration(), 3) . " seconds\n";
            $this->printTotals($report->getResults(), '    ');
        }
    }

    protected function printTotals(EvaluationResults $results, string $indent = ''): void
    {
        echo $indent . sprintf(
            "Tests: %d, Passed: %d, Failed: %d, Success Rate: %s%%\n",
            $results->getTotalCount(),
            $results->getPassedCount(),
            $results->getFailedCount(),
            round($results->getSuccessRate() * 100, 1),
        );

        echo $indent . sprintf(
            "Assertions: %d, Passed: %d, Failed: %d, Success Rate: %s%%\n",
            $results->getTotalAssertions(),
            $results->getTotalAssertionsPassed(),
            $results->getTotalAssertionsFailed(),
            round($results->getAssertionSuccessRate() * 100, 1),
        );

        $cachedRuns = $results->getCachedRunCount();
        if ($cachedRuns > 0) {
            echo $indent . sprintf(
                "Cached runs: %d of %d (assertions re-evaluated)\n",
                $cachedRuns,
                $results->getTotalCount(),
            );
        }

        if ($results->getAllAssertionScores() === []) {
            return;
        }

        echo $indent . sprintf(
            "Score Stats: Avg: %s, Min: %s, Max: %s\n",
            round($results->getAverageAssertionScore(), 3),
            round($results->getMinAssertionScore(), 3),
            round($results->getMaxAssertionScore(), 3),
        );

        foreach ($results->getScoreStatisticsByLabel() as $label => $stats) {
            echo $indent . sprintf(
                "  %s: Avg: %s, Min: %s, Max: %s (%d assertions)\n",
                $label,
                round($stats['average'], 3),
                round($stats['min'], 3),
                round($stats['max'], 3),
                $stats['count'],
            );
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getEvaluatorLabels(EvaluationReport $suite): array
    {
        $shortNames = [];
        foreach ($suite->getEvaluatorReports() as $report) {
            $shortNames[$report->getEvaluatorClass()] = $report->getShortEvaluatorClass();
        }

        $shortNameCounts = array_count_values($shortNames);
        $labels = [];

        foreach ($shortNames as $evaluatorClass => $shortName) {
            $labels[$evaluatorClass] = $shortNameCounts[$shortName] > 1
                ? $evaluatorClass
                : $shortName;
        }

        return $labels;
    }

    /** @param array<string, string> $evaluatorLabels */
    protected function printFailures(EvaluationResults $results, array $evaluatorLabels): void
    {
        echo "There were " . $results->getFailedCount() . " failure(s):\n\n";

        $failureCount = 1;
        foreach ($results->getFailedResults() as $result) {
            echo "{$failureCount}) {$evaluatorLabels[$result->getEvaluatorClass()]} #{$result->getIndex()}\n";

            if ($result->hasError()) {
                echo "   Error: " . $result->getError() . "\n";
            } else {
                echo "   Evaluation failed\n";
                if ($this->verbose) {
                    echo "   Input: " . json_encode($result->getInput(), JSON_PRETTY_PRINT) . "\n";
                    echo "   Output: " . $this->formatOutput($result->getOutput()) . "\n";
                }
            }

            if ($result->getTotalAssertions() > 0) {
                echo sprintf(
                    "   Assertions: %d passed, %d failed\n",
                    $result->getAssertionsPassed(),
                    $result->getAssertionsFailed()
                );
            }

            echo "   Execution Time: " . round($result->getExecutionTime(), 3) . "s\n\n";
            $failureCount++;
        }
    }

    protected function formatOutput(mixed $output): string
    {
        if (is_string($output)) {
            return '"' . $output . '"';
        }

        if (is_array($output) || is_object($output)) {
            return json_encode($output, JSON_PRETTY_PRINT) ?: 'Unable to serialize output';
        }

        if (is_bool($output)) {
            return $output ? 'true' : 'false';
        }

        if ($output === null) {
            return 'null';
        }

        return (string) $output;
    }

    /** @param array<string, string> $evaluatorLabels */
    protected function printAssertionFailureSummary(EvaluationResults $results, array $evaluatorLabels): void
    {
        $failuresByLocation = [];
        foreach ($results->getAllAssertionFailures() as $failure) {
            $location = $evaluatorLabels[$failure->getEvaluatorClass()]
                . ':'
                . $failure->getLineNumber();
            $failuresByLocation[$location][] = $failure;
        }

        if ($failuresByLocation === []) {
            return;
        }

        echo "Assertion Failure Summary:\n";
        echo str_repeat('-', 50) . "\n";

        foreach ($failuresByLocation as $location => $failures) {
            $failureCount = count($failures);
            $uniqueAssertions = array_unique(array_map(fn (AssertionFailure $f): string => $f->getAssertionMethod(), $failures));

            echo sprintf(
                "%s - %d failure%s in %s\n",
                $location,
                $failureCount,
                $failureCount === 1 ? '' : 's',
                implode(', ', $uniqueAssertions)
            );

            if ($this->verbose) {
                foreach ($failures as $failure) {
                    echo sprintf("  - %s: %s\n", $failure->getAssertionMethod(), $failure->getMessage());
                }
            }
        }

        echo "\n";
    }
}
