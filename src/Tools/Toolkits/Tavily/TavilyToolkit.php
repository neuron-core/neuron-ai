<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 */
class TavilyToolkit extends AbstractToolkit
{
    public function __construct(
        protected string $key,
        protected ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function guidelines(): ?string
    {
        return <<<GUIDELINES
            When a question starts from a search, read a result with url_reader only if the web_search
            descriptions do not already answer it. Use url_crawl only when reading single pages is not enough.
            GUIDELINES;
    }

    public function provide(): array
    {
        return [
            new TavilyExtractTool($this->key, $this->httpClient),
            new TavilySearchTool($this->key, httpClient: $this->httpClient),
            new TavilyCrawlTool($this->key, $this->httpClient),
        ];
    }
}
