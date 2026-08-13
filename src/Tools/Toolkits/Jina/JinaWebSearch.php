<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Jina;

use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;

use function implode;

/**
 * @method static make(string $key, array $topics)
 */
class JinaWebSearch extends Tool
{
    protected HttpClientInterface $client;

    protected string $name = 'web_search';
    protected ?string $description = 'Use this tool to search the web for additional information if the question is outside the scope of the context you have.';

    public function __construct(
        protected string $key,
        array $topics = [],
    ) {
        if ($topics !== []) {
            $this->description = 'Use this tool to search the web for additional information '.
                'about '.implode(', ', $topics).', or '.
                'if the question is outside the scope of the context you have.';
        }
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
        ];
    }

    protected function getClient(): HttpClientInterface
    {
        return $this->client ??= new CurlHttpClient(
            customHeaders: [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'X-Respond-With' => 'no-content',
            ],
        );
    }

    public function __invoke(string $search_query): string
    {
        $response = $this->getClient()->request(HttpRequest::post('https://s.jina.ai/', [
            'q' => $search_query,
        ]));

        return $response->body;
    }
}
