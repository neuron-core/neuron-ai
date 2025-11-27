<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

trait HasGuzzleClient
{
    protected Client $client;

    public function setClient(Client $client): AIProviderInterface
    {
        $this->client = $client;
        return $this;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function mergeHttpOptions(array $config, HttpClientOptions $options): array
    {
        if ($options->headers !== null && $options->headers !== []) {
            $config['headers'] = \array_merge($config['headers'], $options->headers);
        }

        // Handle individual options
        if ($options->timeout !== null) {
            $config['timeout'] = $options->timeout;
        }
        if ($options->connectTimeout !== null) {
            $config['connect_timeout'] = $options->connectTimeout;
        }
        if ($options->handler instanceof HandlerStack) {
            $config['handler'] = $options->handler;
        }
        if ($options->proxy !== null) {
            $config['proxy'] = $options->proxy;
        }

        return $config;
    }
}
