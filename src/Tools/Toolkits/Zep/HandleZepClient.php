<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Zep;

use Exception;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;

use function trim;

/**
 * @deprecated The Zep toolkit will be removed in the next major version.
 */
trait HandleZepClient
{
    protected HttpClientInterface $httpClient;

    protected string $url = 'https://api.getzep.com/api/v2';

    protected function get(string $endpoint): HttpResponse
    {
        return $this->httpClient->request(HttpRequest::get(trim($this->url, '/').'/'.$endpoint, $this->headers()));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function post(string $endpoint, array $body): HttpResponse
    {
        return $this->httpClient->request(HttpRequest::post(trim($this->url, '/').'/'.$endpoint, $body, $this->headers()));
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Authorization' => "Api-Key {$this->key}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function createUser(): self
    {
        // Create the user if it doesn't exist
        try {
            $this->get('users/'.$this->user_id);
        } catch (Exception) {
            $this->post('users', ['user_id' => $this->user_id]);
        }

        return $this;
    }
}
