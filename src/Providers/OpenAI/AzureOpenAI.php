<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\HttpClient\HttpClientInterface;

use function preg_replace;

class AzureOpenAI extends OpenAI
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $endpoint,
        protected string $model,
        protected bool $strict_response = false,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpClient);

        $this->baseUri = 'https://'.preg_replace('/^https?:\/\/|\/+$/', '', $endpoint).'/openai/v1';

        // Resource keys go in api-key: a Bearer token is only for Microsoft Entra ID
        unset($this->httpHeaders['Authorization']);
        $this->httpHeaders['api-key'] = $key;
    }
}
