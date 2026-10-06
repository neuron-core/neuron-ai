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
        return "- The web search tool is your discovery mechanism for exploring topics and finding multiple sources.\n
        - The URL reader returns the full content of a known page as Markdown, after you've identified
        a page of interest through search or the user gave you its address.\n\n
        Effective search queries should be specific and targeted, typically using two to four keywords rather than
        broad terms. Read a page only when the search results do not already answer the question.";
    }

    public function provide(): array
    {
        return [
            new FirecrawlSearchTool($this->key, httpClient: $this->httpClient),
            new FirecrawlScrapeTool($this->key, $this->httpClient),
        ];
    }
}
