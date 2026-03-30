<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use Throwable;

use function microtime;

class EvaluatorRunner
{
    /**
     * Run the evaluator and return a summary of results
     */
    public function run(EvaluatorInterface $evaluator): EvaluatorSummary
    {
        $evaluator->setUp();

        $dataset = $evaluator->getDataset();
        $data = $dataset->load();
        $results = [];
        $totalTime = 0.0;

        foreach ($data as $index => $item) {
            $startTime = microtime(true);
            $error = null;
            $output = null;
            $outcomes = null;

            try {
                $output = $evaluator->run($item);
                $outcomes = $evaluator->performEvaluation($output, $item);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $executionTime = microtime(true) - $startTime;
            $totalTime += $executionTime;

            $results[] = new EvaluatorResult(
                $index,
                $outcomes !== null && $outcomes->isPassed(),
                $item,
                $output,
                $executionTime,
                $outcomes instanceof \NeuronAI\Evaluation\AssertionOutcomes ? $outcomes->passedCount : 0,
                $outcomes instanceof \NeuronAI\Evaluation\AssertionOutcomes ? $outcomes->failedCount : 0,
                $outcomes instanceof \NeuronAI\Evaluation\AssertionOutcomes ? $outcomes->failures : [],
                $outcomes instanceof \NeuronAI\Evaluation\AssertionOutcomes ? $outcomes->scores : [],
                $error
            );
        }

        return new EvaluatorSummary($results, $totalTime);
    }
}
