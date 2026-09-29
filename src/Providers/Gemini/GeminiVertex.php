<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\HandleGoogleServiceAccount;

class GeminiVertex extends Gemini
{
    use HandleGoogleServiceAccount;

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
        // Set Vertex AI specific base URI
        $this->baseUri = $location !== null
            ? "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/google/models"
            : "https://aiplatform.googleapis.com/v1/projects/{$projectId}/locations/global/publishers/google/models";

        $this->useServiceAccount($pathJsonCredentials);

        // Bearer token authentication (see requestHeaders()), no x-goog-api-key
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->httpHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }
}
