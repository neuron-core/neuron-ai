<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Results\EvaluationResult;
use NeuronAI\Evaluation\Results\EvaluationSummary;
use Throwable;

class EvaluationRunner
{
    public function run(EvaluatorInterface $evaluator): EvaluationSummary
    {
        // Call setUp before starting evaluation
        if (\method_exists($evaluator, 'setUp')) {
            $evaluator->setUp();
        }

        $dataset = $evaluator->getDataset();
        $data = $dataset->load();
        $results = [];
        $totalTime = 0.0;

        foreach ($data as $index => $item) {
            $startTime = \microtime(true);
            $passed = false;
            $error = null;
            $output = null;

            // Reset assertion counts before running evaluator
            if (\method_exists($evaluator, 'resetAssertionCounts')) {
                $evaluator->resetAssertionCounts();
            }

            try {
                $output = $evaluator->run($item);
                $passed = $evaluator->evaluate($output, $item);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $executionTime = \microtime(true) - $startTime;
            $totalTime += $executionTime;

            // Capture assertion counts and failures
            $assertionsPassed = \method_exists($evaluator, 'getAssertionsPassed') ? $evaluator->getAssertionsPassed() : 0;
            $assertionsFailed = \method_exists($evaluator, 'getAssertionsFailed') ? $evaluator->getAssertionsFailed() : 0;
            $assertionFailures = \method_exists($evaluator, 'getAssertionFailures') ? $evaluator->getAssertionFailures() : [];

            $results[] = new EvaluationResult(
                $index,
                $passed,
                $item,
                $output,
                $executionTime,
                $assertionsPassed,
                $assertionsFailed,
                $assertionFailures,
                $error
            );
        }

        return new EvaluationSummary($results, $totalTime);
    }
}
