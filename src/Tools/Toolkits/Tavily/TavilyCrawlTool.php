<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_merge;
use function filter_var;
use function json_decode;
use function trim;

use const FILTER_VALIDATE_URL;

/**
 * @method static static make(string $key)
 */
class TavilyCrawlTool extends Tool
{
    protected Client $client;

    protected string $url = 'https://api.tavily.com/';

    protected array $options = [
        'include_images' => false,
        'allow_external' => false
    ];

    /**
     * @param string $key Tavily API key.
     */
    public function __construct(
        protected string $key,
    ) {
        parent::__construct(
            'url_crawl',
            'Get the entire website in markdown format.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'url',
                PropertyType::STRING,
                'The URL to crawl.',
                true
            ),
        ];
    }

    public function __invoke(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ToolException('Invalid URL.');
        }

        $result = $this->getClient()->post('crawl', [
            RequestOptions::JSON => array_merge(
                $this->options,
                ['url' => $url]
            )
        ])->getBody()->getContents();

        return json_decode($result, true);
    }


    protected function getClient(): Client
    {
        return $this->client ?? $this->client = new Client([
            'base_uri' => trim($this->url, '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
