<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;

/**
 * @deprecated The Supadata toolkit will be removed in the next major version.
 */
trait HttpClient
{
    protected HttpClientInterface $httpClient;

    protected function get(string $endpoint): HttpResponse
    {
        return $this->httpClient->request(HttpRequest::get('https://api.supadata.ai/v1/'.$endpoint, [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->key,
        ]));
    }
}
