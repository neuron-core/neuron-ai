<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Client;
use NeuronAI\Providers\HttpClientOptions;

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
        protected ?\NeuronAI\Providers\HttpClientOptions $httpOptions = null,
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpOptions);

        $this->setBaseUrl();

        $config = [
            'base_uri' => $this->baseUri,
            'query'    => [
                'api-version' => $this->version,
            ],
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if ($this->httpOptions instanceof HttpClientOptions) {
            $config = $this->mergeHttpOptions($config, $this->httpOptions);
        }

        $this->client = new Client($config);
    }

    private function setBaseUrl(): void
    {
        $this->endpoint = \preg_replace('/^https?:\/\/([^\/]*)\/?$/', '$1', $this->endpoint);
        $this->baseUri = \sprintf($this->baseUri, $this->endpoint, $this->model);
        $this->baseUri = \trim($this->baseUri, '/').'/';
    }
}
