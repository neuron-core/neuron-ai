<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Jina;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function implode;

/**
 * @method static static make(string $key, array $topics = [], ?HttpClientInterface $httpClient = null)
 */
class JinaWebSearch extends Tool
{
    protected HttpClientInterface $httpClient;

    protected string $name = 'web_search';
    protected ?string $description;

    public function __construct(
        protected string $key,
        array $topics = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->description = 'Search the web and return the most relevant pages, each with its title, URL and a short description of its content. '.
            'Use it '.($topics === [] ? '' : 'for questions about '.implode(', ', $topics).', or ').
            'when the question is outside the scope of the context you have. '.
            'A result describes its page and does not carry its full content.';
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'search_query',
                PropertyType::STRING,
                'A concise query about a single subject, written as you would type it in a search engine. Run a separate search for each subject of a complex question.',
                true
            ),
        ];
    }

    public function __invoke(string $search_query): string
    {
        $response = $this->httpClient->request(HttpRequest::post('https://s.jina.ai/', [
            'q' => $search_query,
        ], [
            'Authorization' => 'Bearer '.$this->key,
            'Content-Type' => 'application/json',
            'X-Respond-With' => 'no-content',
        ]));

        return $response->body;
    }
}
