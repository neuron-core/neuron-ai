<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use NeuronAI\Providers\HttpClientOptions;

class GeminiVertex extends Gemini
{
    protected string $key = ''; // Not used for Vertex AI, but required by parent

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $pathJsonCredentials,
        string $location,
        string $projectId,
        protected string $model,
        protected array $parameters = [],
        protected ?HttpClientOptions $httpOptions = null,
    ) {
        // Set Vertex AI specific base URI
        $this->baseUri = "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/google/models";

        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $pathJsonCredentials
        );

        $config = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // Configure the HTTP client with Bearer token authentication (no x-goog-api-key)
                'Authorization' => $credentials->fetchAuthToken(),
            ]
        ];

        if ($this->httpOptions instanceof HttpClientOptions) {
            $config = $this->mergeHttpOptions($config, $this->httpOptions);
        }

        $this->client = new Client($config);
    }
}
