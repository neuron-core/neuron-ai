<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;

use function trim;

trait HandleTavilyClient
{
    protected HttpClientInterface $httpClient;

    protected string $url = 'https://api.tavily.com/';

    /**
     * @param array<string, mixed> $body
     */
    protected function post(string $endpoint, array $body): HttpResponse
    {
        return $this->httpClient->request(HttpRequest::post(
            uri: trim($this->url, '/').'/'.$endpoint,
            body: $body,
            headers: [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ));
    }
}
