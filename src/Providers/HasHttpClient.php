<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Providers\HttpClient\HttpClientInterface;

/**
 * Trait for providers using HTTP client abstraction.
 */
trait HasHttpClient
{
    protected HttpClientInterface $httpClient;

    /**
     * Set a custom HTTP client implementation.
     */
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        $this->httpClient = $client;
        return $this;
    }

    /**
     * Get the current HTTP client.
     */
    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }
}
