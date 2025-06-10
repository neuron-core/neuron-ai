<?php

namespace NeuronAI\Tools\Toolkits\Tavily;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

class TavilySearchTool extends Tool
{
    protected Client $client;

    protected string $url = 'https://api.tavily.com/';

    protected array $options = [
        'search_depth' => 'basic',
        'chunks_per_source' => 3,
        'max_results' => 1,
    ];

    /**
     * @param string $key Tavily API key.
     * @param array $topics Explicit the topics you want to force the Agent to perform web search.
     */
    public function __construct(
        protected string $key,
        protected array $topics = [],
    ) {
        parent::__construct(
            'web_search',
            'Use this tool to search the web for additional information '.
            (!empty($this->topics) ? 'about '.implode(', ', $this->topics).', or ' : '').
            'if the question is outside the scope of the context you have.'
        );

        $this->addProperty(
            new ToolProperty(
                'search_query',
                PropertyType::STRING,
                'The search query to perform web search.',
                true
            ),
        )->addProperty(
            new ToolProperty(
                'topic',
                PropertyType::STRING,
                'Explicit the topic you want to perform the web search on.',
                false,
                ['general', 'news']
            ),
        )->addProperty(
            new ToolProperty(
                'time_range',
                PropertyType::STRING,
                '',
                false,
                ['day, week, month, year']
            )
        )->addProperty(
            new ToolProperty(
                'days',
                PropertyType::INTEGER,
                'Filter search results for a certain range of days up to today.',
                false,
            )
        )->setCallable($this);
    }

    protected function getClient(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        return $this->client = new Client([
            'base_uri' => trim($this->url, '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
    }

    public function __invoke(
        string $search_query,
        string $topic = 'general',
        string $time_range = 'day',
        int $days = 7,
    ) {
        $result = $this->getClient()->post('search', [
            RequestOptions::JSON => \array_merge(
                compact('topic', 'time_range', 'days'),
                $this->options,
                [
                    'query' => $search_query,
                ]
            )
        ])->getBody()->getContents();

        $result = \json_decode($result, true);

        return [
            'answer' => $result['answer'],
            'results' => \array_map(fn ($item) => [
                'title' => $item['title'],
                'url' => $item['url'],
                'content' => $item['content'],
            ], $result['results']),
        ];
    }

    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }
}
