<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;

use function trim;

trait HandleTavilyClient
{
    protected HttpClientInterface $client;

    protected string $url = 'https://api.tavily.com/';

    protected function getClient(): HttpClientInterface
    {
        return $this->client ??= (new CurlHttpClient(
            customHeaders: [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ))->withBaseUri(trim($this->url, '/').'/');
    }
}
