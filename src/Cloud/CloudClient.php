<?php

declare(strict_types=1);

namespace NeuronAI\Cloud;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\StaticConstructor;
use RuntimeException;

use function fclose;
use function fwrite;
use function is_resource;
use function json_encode;
use function proc_close;
use function proc_open;
use function rtrim;
use function sprintf;
use function str_replace;

use const JSON_THROW_ON_ERROR;
use const PHP_OS_FAMILY;

/**
 * HTTP client for the Neuron Cloud API.
 *
 * On Linux/macCS, sends requests asynchronously via a background curl process.
 * On Windows, falls back to synchronous Guzzle requests.
 */
class CloudClient
{
    use StaticConstructor;

    protected string $baseUri = 'https://cloud.neuron-ai.dev/api';
    protected GuzzleHttpClient $httpClient;

    public function __construct(
        protected string $apiKey,
        ?string $baseUri = null,
    ) {
        $this->baseUri = $baseUri ?? $_ENV['NEURON_CLOUD_URL'] ?? $this->baseUri;

        $this->httpClient = (new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->withTimeout(5.0);
    }

    public function sendTrace(array $payload): HttpResponse
    {
        if ($this->canRunInBackground()) {
            $this->postAsync('traces', $payload);

            return new HttpResponse(202, '{}', []);
        }

        return $this->httpClient->request(
            HttpRequest::post('traces', $payload)
        );
    }

    public function sendEvaluation(array $payload): HttpResponse
    {
        if ($this->canRunInBackground()) {
            $this->postAsync('evaluations', $payload);

            return new HttpResponse(202, '{}', []);
        }

        return $this->httpClient->request(
            HttpRequest::post('evaluations', $payload)
        );
    }

    /**
     * Post data asynchronously using a background curl process.
     *
     * Pipes the JSON payload through stdin to avoid shell escaping
     * issues and ARG_MAX limits with large payloads.
     */
    protected function postAsync(string $endpoint, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $url = rtrim($this->baseUri, '/') . '/' . $endpoint;

        $command = $this->buildCurlCommand($url);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn background curl process.');
        }

        fwrite($pipes[0], $json);
        fclose($pipes[0]);

        proc_close($process);
    }

    protected function buildCurlCommand(string $url): string
    {
        return sprintf(
            'curl -s -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "Authorization: Bearer %s" -d @- %s &',
            $this->escapeShellArg($this->apiKey),
            $this->escapeShellArg($url),
        );
    }

    protected function canRunInBackground(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }

    protected function escapeShellArg(string $arg): string
    {
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }
}
