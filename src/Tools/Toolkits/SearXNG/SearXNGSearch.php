<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\SearXNG;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function array_map;
use function implode;
use function json_decode;
use function trim;

/**
 * @method static static make(string $baseUrl, array $topics = [])
 */
class SearXNGSearch extends Tool
{
    protected Client $client;

    /**
     * @param string $baseUrl The base URL of the SearXNG instance.
     * @param array $topics Explicit the topics you want to force the Agent to perform web search.
     */
    public function __construct(
        protected string $baseUrl,
        protected array $topics = [],
    ) {
        parent::__construct(
            'web_search',
            'Use this tool to search the web for additional information '.
            ($this->topics === [] ? '' : 'about '.implode(', ', $this->topics).', or ').
            'if the question is outside the scope of the context you have.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'search_query',
                PropertyType::STRING,
                'The search query to perform web search.',
                true
            ),
            new ToolProperty(
                'categories',
                PropertyType::STRING,
                'Comma separated list of categories to search in (e.g., general, science, news).',
                false
            ),
            new ToolProperty(
                'engines',
                PropertyType::STRING,
                'Comma separated list of engines to use.',
                false
            ),
            new ToolProperty(
                'language',
                PropertyType::STRING,
                'The language for the search (e.g., en-US).',
                false
            ),
            new ToolProperty(
                'time_range',
                PropertyType::STRING,
                'Filter results by time range.',
                false,
                ['day', 'week', 'month', 'year']
            ),
        ];
    }

    protected function getClient(): Client
    {
        return $this->client ?? $this->client = new Client([
            'base_uri' => trim($this->baseUrl, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * @return array<string, mixed>
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function __invoke(
        string $search_query,
        ?string $categories = null,
        ?string $engines = null,
        ?string $language = null,
        ?string $time_range = null,
    ): array {
        $queryParameters = [
            'q' => $search_query,
            'format' => 'json',
        ];

        if ($categories) {
            $queryParameters['categories'] = $categories;
        }

        if ($engines) {
            $queryParameters['engines'] = $engines;
        }

        if ($language) {
            $queryParameters['language'] = $language;
        }

        if ($time_range) {
            $queryParameters['time_range'] = $time_range;
        }

        $response = $this->getClient()->get('search', [
            RequestOptions::QUERY => $queryParameters
        ])->getBody()->getContents();

        $result = json_decode($response, true);

        return [
            'results' => array_map(fn (array $item): array => [
                'title' => $item['title'] ?? '',
                'url' => $item['url'] ?? '',
                'content' => $item['content'] ?? '',
            ], $result['results'] ?? []),
        ];
    }
}
