<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Firecrawl;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;

use function array_merge;
use function trim;

trait HandleFirecrawlClient
{
    protected HttpClientInterface $httpClient;

    protected string $url = 'https://api.firecrawl.dev/v2/';

    /**
     * @param array<string, mixed> $body
     */
    protected function post(string $endpoint, array $body): HttpResponse
    {
        return $this->httpClient->request(HttpRequest::post(
            uri: trim($this->url, '/').'/'.$endpoint,
            body: array_merge($body, ['origin' => 'neuron-ai']),
            headers: [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ));
    }
}
