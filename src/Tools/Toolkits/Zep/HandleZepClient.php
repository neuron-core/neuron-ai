<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Zep;

use Exception;
use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

use function trim;

trait HandleZepClient
{
    protected HttpClientInterface $client;

    protected string $url = 'https://api.getzep.com/api/v2';

    protected function getClient(): HttpClientInterface
    {
        return $this->client ??= (new CurlHttpClient(
            customHeaders: [
                'Authorization' => "Api-Key {$this->key}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ))->withBaseUri(trim($this->url, '/').'/');
    }

    protected function createUser(): self
    {
        // Create the user if it doesn't exist
        try {
            $this->getClient()->request(HttpRequest::get('users/'.$this->user_id));
        } catch (Exception) {
            $this->getClient()->request(HttpRequest::post('users', ['user_id' => $this->user_id]));
        }

        return $this;
    }
}
