<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;

use function http_build_query;

/**
 * @deprecated The Supadata toolkit will be removed in the next major version.
 */
trait HttpClient
{
    protected HttpClientInterface $httpClient;

    /**
     * @param array<string, string> $query
     */
    protected function get(string $endpoint, array $query): HttpResponse
    {
        return $this->httpClient->request(HttpRequest::get('https://api.supadata.ai/v1/'.$endpoint.'?'.http_build_query($query), [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->key,
        ]));
    }
}
