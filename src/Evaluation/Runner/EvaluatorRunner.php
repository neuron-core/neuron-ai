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
            $assertionsPassed = $evaluator->getAssertionsPassed();
            $assertionsFailed = $evaluator->getAssertionsFailed();
            $assertionFailures = $evaluator->getAssertionFailures();

            $results[] = new EvaluatorResult(
                $index,
                $assertionsFailed === 0,
                $item,
                $output,
                $executionTime,
                $assertionsPassed,
                $assertionsFailed,
                $assertionFailures,
                $error
            );
        }

        return new EvaluatorSummary($results, $totalTime);
    }
}
