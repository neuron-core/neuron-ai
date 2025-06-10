<?php

namespace NeuronAI\Tools\Toolkits\Jina;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

class JinaWebSearch extends Tool
{
    protected Client $client;

    public function __construct(
        protected string $key,
        array $topics = [],
    ) {
        parent::__construct(
            'web_search',
            'Use this tool to search the web for additional information '.
            (!empty($topics) ? 'about '.implode(', ', $topics).', or ' : '').
            'if the question is outside the scope of the context you have.'
        );

        $this->addProperty(
            new ToolProperty(
                'search_query',
                PropertyType::STRING,
                'The search query to perform web search.',
                true
            )
        )->setCallable($this);
    }

    protected function getClient(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        return $this->client = new Client([
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'X-Respond-With' => 'no-content',
            ]
        ]);
    }

    public function __invoke(string $search_query): string
    {
        return $this->getClient()->post('https://s.jina.ai/', [
            RequestOptions::JSON => [
                'q' => $search_query,
            ]
        ])->getBody()->getContents();
    }
}
