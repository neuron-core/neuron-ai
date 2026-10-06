<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Firecrawl;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 */
class FirecrawlToolkit extends AbstractToolkit
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
            descriptions do not already answer it.
            GUIDELINES;
    }

    public function provide(): array
    {
        return [
            new FirecrawlSearchTool($this->key, httpClient: $this->httpClient),
            new FirecrawlScrapeTool($this->key, $this->httpClient),
        ];
    }
}
