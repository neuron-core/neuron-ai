<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;

trait HttpClient
{
    protected HttpClientInterface $client;

    public function getClient(string $key): HttpClientInterface
    {
        return $this->client ??= (new CurlHttpClient(
            customHeaders: [
                'Content-Type' => 'application/json',
                'x-api-key' => $key,
            ],
        ))->withBaseUri('https://api.supadata.ai/v1/');
    }
}
