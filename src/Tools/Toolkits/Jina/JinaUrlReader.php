<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Jina;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function filter_var;

use const FILTER_VALIDATE_URL;

/**
 * @method static make(string $key)
 */
class JinaUrlReader extends Tool
{
    protected Client $client;

    public function __construct(protected string $key)
    {
        parent::__construct(
            'url_reader',
            'Get the content of a URL in markdown format.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'url',
                PropertyType::STRING,
                'The URL to read.',
                true
            ),
        ];
    }

    protected function getClient(): Client
    {
        return $this->client ?? $this->client = new Client([
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Return-Format' => 'Markdown',
            ]
        ]);
    }

    public function __invoke(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ToolException('Invalid URL.');
        }

        return $this->getClient()->post('https://r.jina.ai/', [
            RequestOptions::JSON => [
                'url' => $url,
            ]
        ])->getBody()->getContents();
    }
}
