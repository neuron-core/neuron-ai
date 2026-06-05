<?php

declare(strict_types=1);

namespace NeuronAI\Cloud;

use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use NeuronAI\StaticConstructor;
use Throwable;

use function array_map;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function microtime;

/**
 * Send evaluation results to the Neuron Cloud platform.
 */
class CloudEvaluationOutput implements EvaluationOutputInterface
{
    use StaticConstructor;

    public function __construct(
        protected CloudClient $client,
    ) {
    }

    public function output(EvaluatorSummary $summary): void
    {
        $payload = $this->summaryToPayload($summary);

        try {
            $this->client->sendEvaluation($payload);
        } catch (Throwable) {
            // Silently ignore — evaluation output must not crash the runner.
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function summaryToPayload(EvaluatorSummary $summary): array
    {
        return [
            'timestamp' => (int) (microtime(true) * 1000),
            'summary' => [
                'total' => $summary->getTotalCount(),
                'passed' => $summary->getPassedCount(),
                'failed' => $summary->getFailedCount(),
                'success_rate' => $summary->getSuccessRate(),
                'total_execution_time' => $summary->getTotalExecutionTime(),
                'average_execution_time' => $summary->getAverageExecutionTime(),
                'total_assertions' => $summary->getTotalAssertions(),
                'assertions_passed' => $summary->getTotalAssertionsPassed(),
                'assertions_failed' => $summary->getTotalAssertionsFailed(),
                'assertion_success_rate' => $summary->getAssertionSuccessRate(),
                'has_failures' => $summary->hasFailures(),
            ],
            'results' => array_map(
                $this->resultToPayload(...),
                $summary->getResults(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultToPayload(EvaluatorResult $r): array
    {
        return [
            'index' => $r->getIndex(),
            'passed' => $r->isPassed(),
            'input' => $r->getInput(),
            'output' => $this->formatOutput($r->getOutput()),
            'execution_time' => $r->getExecutionTime(),
            'error' => $r->getError(),
            'assertions_passed' => $r->getAssertionsPassed(),
            'assertions_failed' => $r->getAssertionsFailed(),
            'assertion_scores' => $r->getAssertionScores(),
        ];
    }

    protected function formatOutput(mixed $output): mixed
    {
        if (is_string($output) || is_int($output) || is_float($output)
            || is_bool($output) || $output === null
        ) {
            return $output;
        }

        if (is_array($output) || is_object($output)) {
            return json_encode($output) ?: 'Unable to serialize output';
        }

        return (string) $output;
    }
}
