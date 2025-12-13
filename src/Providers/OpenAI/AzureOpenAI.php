<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Providers\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\HttpClient\HttpClientInterface;

use function preg_replace;
use function sprintf;
use function trim;

class AzureOpenAI extends OpenAI
{
    protected string $baseUri = "https://%s/openai/deployments/%s";

    public function __construct(
        protected string $key,
        protected string $endpoint,
        protected string $model,
        protected string $version,
        protected bool $strict_response = false,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->setBaseUrl();

        // Create HTTP client with Azure-specific configuration
        // Azure uses Bearer token auth instead of api-key header
        // and requires api-version as query parameter
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        // Note: Azure api-version query parameter is handled by Azure-specific request building
        // Store version for use in requests
        $this->parameters['api-version'] = $this->version;
    }

    private function setBaseUrl(): void
    {
        $this->endpoint = preg_replace('/^https?:\/\/([^\/]*)\/?$/', '$1', $this->endpoint);
        $this->baseUri = sprintf($this->baseUri, $this->endpoint, $this->model);
        $this->baseUri = trim($this->baseUri, '/').'/';
    }
}
