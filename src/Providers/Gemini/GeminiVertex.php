<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use Google\Auth\Credentials\ServiceAccountCredentials;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;

use function time;

class GeminiVertex extends Gemini
{
    protected string $key = ''; // Not used for Vertex AI, but required by parent
    protected ServiceAccountCredentials $credentials;
    protected ?int $tokenExpiresAt = null;
    protected ?string $currentToken = null;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $pathJsonCredentials,
        string $location,
        string $projectId,
        protected string $model,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        // Set Vertex AI specific base URI
        $this->baseUri = "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/google/models";

        // Store credentials for token refresh
        $this->credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $pathJsonCredentials
        );

        // Configure the HTTP client with Bearer token authentication (no x-goog-api-key)
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->refreshToken(),
            ]);
    }

    /**
     * Get a valid OAuth token, refreshing if expired.
     */
    public function refreshToken(): string
    {
        // 5-minute buffer
        if ($this->currentToken !== null && $this->tokenExpiresAt !== null && time() < $this->tokenExpiresAt - 300) {
            return $this->currentToken;
        }

        // Fetch new token from Google
        $tokenData = $this->credentials->fetchAuthToken();

        // Store token and calculate expiration time
        $this->currentToken = $tokenData['access_token'];
        $expiresIn = $tokenData['expires_in'] ?? 3600;
        $this->tokenExpiresAt = time() + $expiresIn;

        return $this->currentToken;
    }
}
