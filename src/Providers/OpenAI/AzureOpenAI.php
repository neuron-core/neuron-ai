<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Client;

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
    ) {
        parent::__construct($key, $model, $parameters, $strict_response);

        $this->setBaseUrl();

        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'query'    => [
                'api-version' => $this->version,
            ],
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    private function setBaseUrl(): void
    {
        $this->endpoint = \preg_replace('/^https?:\/\/([^\/]*)\/?$/', '$1', $this->endpoint);
        $this->baseUri = \sprintf($this->baseUri, $this->endpoint, $this->model);
        $this->baseUri = \trim($this->baseUri, '/').'/';
    }
}
