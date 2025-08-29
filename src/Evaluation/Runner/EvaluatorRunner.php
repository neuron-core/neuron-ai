<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\BaseEvaluator;
use Throwable;

class EvaluatorRunner
{
    public function run(BaseEvaluator $evaluator): EvaluatorSummary
    {
        $evaluator->setUp();

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
                $evaluator->evaluate($output, $item);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $executionTime = \microtime(true) - $startTime;
            $totalTime += $executionTime;

            // Capture assertion counts and failures
            $rulesPassed = $evaluator->getRulesPassed();
            $rulesFailed = $evaluator->getRulesFailed();
            $ruleFailures = $evaluator->getRuleFailures();

            $results[] = new EvaluatorResult(
                $index,
                $rulesFailed === 0,
                $item,
                $output,
                $executionTime,
                $rulesPassed,
                $rulesFailed,
                $ruleFailures,
                $error
            );
        }

        return new EvaluatorSummary($results, $totalTime);
    }
}
