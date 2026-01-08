<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use Google\Auth\Credentials\ServiceAccountCredentials;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;

class GeminiVertex extends Gemini
{
    private ServiceAccountCredentials $credentials;
    private ?int $tokenExpiresAt = null;
    private ?string $currentToken = null;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $pathJsonCredentials,
        string $location,
        string $projectId,
        string $model,
        array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->baseUri = "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/google/models";

        // Store credentials for token refresh
        $this->credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $pathJsonCredentials
        );

        // Call parent with empty key - parent will initialize with x-goog-api-key header
        parent::__construct('', $model, $parameters, $httpClient);

        // Override httpClient with proper Vertex AI authentication
        // This replaces the x-goog-api-key header with Bearer token
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->refreshToken(),
            ]);
    }

    /**
     * Get a valid OAuth token, refreshing if expired.
     * This method checks token expiration and only fetches a new token if needed.
     * Uses a 5-minute safety buffer before actual expiration.
     * OAuth tokens typically expire after 1 hour.
     *
     * @return string The valid access token
     */
    public function refreshToken(): string
    {
        // 5 minute buffer
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
