<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

/**
 * Trait for providers using HTTP client abstraction.
 */
trait HasHttpClient
{
    protected HttpClientInterface $httpClient;

    /**
     * Set a custom HTTP client implementation.
     */
    public function setHttpClient(HttpClientInterface $client): self
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
