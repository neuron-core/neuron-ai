<?php

declare(strict_types=1);

namespace NeuronAI\Console\Evaluation;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;

class OutputFormatter
{
    public function __construct(private readonly bool $verbose = false)
    {
    }

    public function printHeader(): void
    {
        echo "Neuron AI Evaluation Runner\n\n";
    }

    public function printProgress(string $evaluatorName, int $current, int $total): void
    {
        if ($this->verbose) {
            echo "Running {$evaluatorName}... [{$current}/{$total}]\n";
        }
    }

    public function printProgressSymbol(bool $passed): void
    {
        if (!$this->verbose) {
            echo $passed ? '.' : 'F';
        }
    }

    public function printSummary(EvaluatorSummary $summary): void
    {
        if (!$this->verbose) {
            echo "\n\n";
        }

        $totalCount = $summary->getTotalCount();
        $passedCount = $summary->getPassedCount();
        $failedCount = $summary->getFailedCount();
        $successRate = \round($summary->getSuccessRate() * 100, 1);
        $totalTime = \round($summary->getTotalExecutionTime(), 3);
        $avgTime = \round($summary->getAverageExecutionTime(), 3);

        if ($summary->hasFailures()) {
            $this->printFailures($summary);
        }

        $this->printAssertionFailureSummary($summary);

        echo \sprintf(
            "Time: %s seconds, Average: %s seconds per test\n\n",
            $totalTime,
            $avgTime
        );

        if ($summary->hasFailures()) {
            echo "FAILURES!\n";
        } else {
            echo "OK\n";
        }

        $totalAssertions = $summary->getTotalAssertions();
        $passedAssertions = $summary->getTotalAssertionsPassed();
        $failedAssertions = $summary->getTotalAssertionsFailed();
        $assertionSuccessRate = \round($summary->getAssertionSuccessRate() * 100, 1);

        echo \sprintf(
            "Tests: %d, Passed: %d, Failed: %d, Success Rate: %s%%\n",
            $totalCount,
            $passedCount,
            $failedCount,
            $successRate
        );

        echo \sprintf(
            "Assertions: %d, Passed: %d, Failed: %d, Success Rate: %s%%\n",
            $totalAssertions,
            $passedAssertions,
            $failedAssertions,
            $assertionSuccessRate
        );
    }

    private function printFailures(EvaluatorSummary $summary): void
    {
        echo "There were " . $summary->getFailedCount() . " failure(s):\n\n";

        $failureCount = 1;
        foreach ($summary->getFailedResults() as $result) {
            echo "{$failureCount}) Test #{$result->getIndex()}\n";

            if ($result->hasError()) {
                echo "   Error: " . $result->getError() . "\n";
            } else {
                echo "   Evaluation failed\n";
                if ($this->verbose) {
                    echo "   Input: " . \json_encode($result->getInput(), \JSON_PRETTY_PRINT) . "\n";
                    echo "   Output: " . $this->formatOutput($result->getOutput()) . "\n";
                }
            }

            if ($result->getTotalAssertions() > 0) {
                echo \sprintf(
                    "   Assertions: %d passed, %d failed\n",
                    $result->getAssertionsPassed(),
                    $result->getAssertionsFailed()
                );
            }

            echo "   Execution Time: " . \round($result->getExecutionTime(), 3) . "s\n\n";
            $failureCount++;
        }
    }

    private function formatOutput(mixed $output): string
    {
        if (\is_string($output)) {
            return '"' . $output . '"';
        }

        if (\is_array($output) || \is_object($output)) {
            return \json_encode($output, \JSON_PRETTY_PRINT) ?: 'Unable to serialize output';
        }

        if (\is_bool($output)) {
            return $output ? 'true' : 'false';
        }

        if ($output === null) {
            return 'null';
        }

        return (string) $output;
    }

    public function printError(string $message): void
    {
        echo "Error: {$message}\n";
    }

    private function printAssertionFailureSummary(EvaluatorSummary $summary): void
    {
        $failuresByLocation = $summary->getAssertionFailuresByLocation();

        if ($failuresByLocation === []) {
            return;
        }

        echo "Assertion Failure Summary:\n";
        echo \str_repeat('-', 50) . "\n";

        foreach ($failuresByLocation as $location => $failures) {
            $failureCount = \count($failures);
            $uniqueAssertions = \array_unique(\array_map(fn (AssertionFailure $f): string => $f->getAssertionMethod(), $failures));

            echo \sprintf(
                "%s - %d failure%s in %s\n",
                $location,
                $failureCount,
                $failureCount === 1 ? '' : 's',
                \implode(', ', $uniqueAssertions)
            );

            if ($this->verbose) {
                foreach ($failures as $failure) {
                    echo \sprintf("  - %s: %s\n", $failure->getAssertionMethod(), $failure->getMessage());
                }
            }
        }

        echo "\n";
    }

    public function printUsage(): void
    {
        echo "Usage:\n";
        echo "  vendor/bin/evaluation <path> [options]\n\n";
        echo "Arguments:\n";
        echo "  path                   Path to directory containing evaluators\n\n";
        echo "Options:\n";
        echo "  --verbose, -v          Show verbose output\n";
        echo "  --help, -h             Show this help message\n";
    }
}
