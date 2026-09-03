<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Output;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use NeuronAI\Evaluation\Runner\EvaluationSuiteSummary;

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

    public function output(EvaluationSuiteSummary $summary): void
    {
        $this->printHeader();
        $this->printSummary($summary);
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

    public function printSummary(EvaluationSuiteSummary $suite): void
    {
        $summary = $suite->getAggregateSummary();
        $evaluatorLabels = $this->getEvaluatorLabels($suite);

        if (!$this->verbose) {
            echo "\n\n";
        }

        $totalTime = round($summary->getTotalExecutionTime(), 3);
        $avgTime = round($summary->getAverageExecutionTime(), 3);

        $this->printEvaluatorErrors($suite, $evaluatorLabels);
        if ($summary->hasFailures()) {
            $this->printFailures($summary, $evaluatorLabels);
        }

        $this->printAssertionFailureSummary($summary, $evaluatorLabels);

        echo sprintf(
            "Time: %s seconds, Average: %s seconds per test\n\n",
            $totalTime,
            $avgTime
        );

        if ($suite->hasFailures()) {
            echo "FAILURES!\n";
        } else {
            echo "OK\n";
        }

        $this->printTotals($summary);

        if (count($suite->getEvaluatorReports()) > 1) {
            $this->printEvaluatorBreakdown($suite, $evaluatorLabels);
        }
    }

    /**
     * @param array<string, string> $evaluatorLabels
     */
    protected function printEvaluatorErrors(EvaluationSuiteSummary $suite, array $evaluatorLabels): void
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
    protected function printEvaluatorBreakdown(EvaluationSuiteSummary $suite, array $evaluatorLabels): void
    {
        echo "\nBy evaluator:\n";

        foreach ($suite->getEvaluatorReports() as $report) {
            echo "  {$evaluatorLabels[$report->getEvaluatorClass()]}\n";
            $this->printTotals($report->getSummary(), '    ');
        }
    }

    protected function printTotals(EvaluatorSummary $summary, string $indent = ''): void
    {
        echo $indent . sprintf(
            "Tests: %d, Passed: %d, Failed: %d, Success Rate: %s%%\n",
            $summary->getTotalCount(),
            $summary->getPassedCount(),
            $summary->getFailedCount(),
            round($summary->getSuccessRate() * 100, 1),
        );

        echo $indent . sprintf(
            "Assertions: %d, Passed: %d, Failed: %d, Success Rate: %s%%\n",
            $summary->getTotalAssertions(),
            $summary->getTotalAssertionsPassed(),
            $summary->getTotalAssertionsFailed(),
            round($summary->getAssertionSuccessRate() * 100, 1),
        );

        $cachedRuns = $summary->getCachedRunCount();
        if ($cachedRuns > 0) {
            echo $indent . sprintf(
                "Cached runs: %d of %d (assertions re-evaluated)\n",
                $cachedRuns,
                $summary->getTotalCount(),
            );
        }

        if ($summary->getAllAssertionScores() === []) {
            return;
        }

        echo $indent . sprintf(
            "Score Stats: Avg: %s, Min: %s, Max: %s\n",
            round($summary->getAverageAssertionScore(), 3),
            round($summary->getMinAssertionScore(), 3),
            round($summary->getMaxAssertionScore(), 3),
        );

        foreach ($summary->getScoreStatisticsByLabel() as $label => $stats) {
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
    protected function getEvaluatorLabels(EvaluationSuiteSummary $suite): array
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
    protected function printFailures(EvaluatorSummary $summary, array $evaluatorLabels): void
    {
        echo "There were " . $summary->getFailedCount() . " failure(s):\n\n";

        $failureCount = 1;
        foreach ($summary->getFailedResults() as $result) {
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
    protected function printAssertionFailureSummary(EvaluatorSummary $summary, array $evaluatorLabels): void
    {
        $failuresByLocation = [];
        foreach ($summary->getAllAssertionFailures() as $failure) {
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
