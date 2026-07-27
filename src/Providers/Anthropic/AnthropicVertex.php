<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use Google\Auth\Credentials\ServiceAccountCredentials;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;

/**
 * Anthropic models (Claude) served through the Google Vertex APIs.
 */
class AnthropicVertex extends Anthropic
{
    protected string $key = ''; // Not used for Vertex AI, but required by the parent

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $pathJsonCredentials,
        ?string $location,
        string $projectId,
        protected string $model,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        // "global" location uses a different host than regional locations.
        $this->baseUri = $location !== null
            ? "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/anthropic/models"
            : "https://aiplatform.googleapis.com/v1/projects/{$projectId}/locations/global/publishers/anthropic/models";

        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $pathJsonCredentials
        );

        $token = $credentials->fetchAuthToken();

        // Initialize the parent provider. The parent's x-api-key/anthropic-version
        // headers are replaced below; Vertex authenticates with a Bearer token and
        // reads the version from the body (see requestBody()).
        parent::__construct(
            key: $this->key,
            model: $model,
            version: 'vertex-2023-10-16',
        );

        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token['access_token'],
            ]);
    }

    /**
     * Vertex locates the model in the URL path and distinguishes chat from
     * streaming via the operation suffix.
     */
    protected function requestUri(bool $stream): string
    {
        $operation = $stream ? 'streamRawPredict' : 'rawPredict';
        return "{$this->model}:{$operation}";
    }

    /**
     * Vertex reads the model from the URL (see requestUri()) and requires the
     * API version in the body instead of a header.
     *
     * @param Message[] $messages
     * @return array<string, mixed>
     */
    protected function requestBody(array $messages, bool $stream = false): array
    {
        $body = parent::requestBody($messages, $stream);

        unset($body['model']);
        $body['anthropic_version'] = $this->version;

        return $body;
    }
}
