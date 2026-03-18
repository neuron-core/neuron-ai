<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\OutputDrivers;

use NeuronAI\Evaluation\Contracts\OutputDriverInterface;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
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

class JsonOutputDriver implements OutputDriverInterface
{
    public function __construct(
        private readonly ?string $path = null
    ) {
    }

    public function output(EvaluatorSummary $summary): void
    {
        $data = $this->summaryToArray($summary);

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
    private function summaryToArray(EvaluatorSummary $summary): array
    {
        return [
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
            'results' => array_map(
                fn (EvaluatorResult $r): array => [
                    'index' => $r->getIndex(),
                    'passed' => $r->isPassed(),
                    'input' => $r->getInput(),
                    'output' => $this->formatOutput($r->getOutput()),
                    'execution_time' => $r->getExecutionTime(),
                    'error' => $r->getError(),
                    'assertions_passed' => $r->getAssertionsPassed(),
                    'assertions_failed' => $r->getAssertionsFailed(),
                ],
                $summary->getResults()
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
