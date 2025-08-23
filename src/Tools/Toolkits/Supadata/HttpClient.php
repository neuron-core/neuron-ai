<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Supadata;

use GuzzleHttp\Client;

trait HttpClient
{
    protected Client $client;

    public function getClient(string $key): Client
    {
        return $this->client ?? $this->client = new Client([
            'base_uri' => 'https://api.supadata.ai/v1/',
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $key,
            ]
        ]);
    }
}
