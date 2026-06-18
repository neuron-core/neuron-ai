<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Output;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;

use function array_map;
use function gmdate;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Sends an evaluation run to a Neuron Cloud platform instance.
 *
 * Configure it in <code>evaluation.php</code>:
 *
 * ```php
 * 'output' => [
 *     [
 *         'class' => NeuronAI\Evaluation\Output\NeuronCloudOutput::class,
 *         'apiKey' => env('NEURON_CLOUD_API_KEY'),
 *         'endpoint' => env('NEURON_CLOUD_ENDPOINT', 'https://cloud.neuron.dev/api/evaluation'),
 *         'name' => 'FaithfulnessEvaluator',
 *         'dataset' => env('NEURON_CLOUD_DATASET'),
 *         'environment' => env('NEURON_CLOUD_ENVIRONMENT', 'production'),
 *     ],
 * ],
 * ```
 *
 * Constructor options are matched by name via {@see \NeuronAI\Evaluation\Config\EvaluationOutputFactory},
 * so use camelCase keys.
 */
class NeuronCloudOutput implements EvaluationOutputInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly string $name,
        private readonly ?string $dataset = null,
        private readonly ?string $environment = null,
        private readonly ?Client $client = null
    ) {
    }

    public function output(EvaluatorSummary $summary): void
    {
        $payload = [
            'name' => $this->name,
            'dataset' => $this->dataset,
            'environment' => $this->environment,
            'started_at' => gmdate('c'),
            'summary' => $this->summaryToArray($summary),
            'results' => array_map(
                fn (EvaluatorResult $result): array => [
                    'index' => $result->getIndex(),
                    'passed' => $result->isPassed(),
                    'input' => $result->getInput(),
                    'output' => $this->formatOutput($result->getOutput()),
                    'execution_time' => $result->getExecutionTime(),
                    'error' => $result->getError(),
                    'assertions_passed' => $result->getAssertionsPassed(),
                    'assertions_failed' => $result->getAssertionsFailed(),
                    'assertion_scores' => $result->getAssertionScores(),
                ],
                $summary->getResults()
            ),
        ];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            error_log('[NeuronCloudOutput] Failed to encode payload: ' . $e->getMessage());

            return;
        }

        try {
            $this->client()->post($this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            // A failed upload must not crash the evaluation run.
            error_log('[NeuronCloudOutput] Failed to send evaluation: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryToArray(EvaluatorSummary $summary): array
    {
        $scores = $summary->getAllAssertionScores();

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
            'score_statistics' => $scores !== [] ? [
                'average_score' => $summary->getAverageAssertionScore(),
                'min_score' => $summary->getMinAssertionScore(),
                'max_score' => $summary->getMaxAssertionScore(),
            ] : null,
            'has_failures' => $summary->hasFailures(),
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

    private function client(): Client
    {
        return $this->client ?? new Client();
    }
}
