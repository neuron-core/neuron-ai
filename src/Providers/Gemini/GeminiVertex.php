<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use Google\Auth\Credentials\ServiceAccountCredentials;
use NeuronAI\HttpClient\HttpClientInterface;

class GeminiVertex extends Gemini
{
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

        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $pathJsonCredentials
        );

        $token = $credentials->fetchAuthToken();

        parent::__construct($token['access_token'], $model, $parameters, $httpClient);
    }
}
