<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Results\EvaluatorResult;
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

            try {
                $output = $evaluator->run($item);
                $passed = $evaluator->evaluate($output, $item);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $executionTime = \microtime(true) - $startTime;
            $totalTime += $executionTime;

            // Capture assertion counts and failures
            $rulesPassed = \method_exists($evaluator, 'getRulesPassed') ? $evaluator->getRulesPassed() : 0;
            $rulesFailed = \method_exists($evaluator, 'getRulesFailed') ? $evaluator->getRulesFailed() : 0;
            $ruleFailures = \method_exists($evaluator, 'getRulesFailures') ? $evaluator->getRuleFailures() : [];

            $results[] = new EvaluatorResult(
                $index,
                $passed,
                $item,
                $output,
                $executionTime,
                $rulesPassed,
                $rulesFailed,
                $ruleFailures,
                $error
            );
        }

        return new EvaluationSummary($results, $totalTime);
    }
}
