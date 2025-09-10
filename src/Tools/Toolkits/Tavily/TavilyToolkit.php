<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Tavily;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $key)
 */
class TavilyToolkit extends AbstractToolkit
{
    public function __construct(protected string $key)
    {
    }

    public function guidelines(): ?string
    {
        return "- The Search API serves as your primary discovery mechanism for exploring topics and finding multiple sources.\n
        - The Extract API functions as your precision instrument for retrieving complete content from known URLs
        after you've identified specific pages of interest.\n- The Crawl API represents your comprehensive exploration
        tool for systematically traversing websites to understand their structure and full content scope.\n\n
        Effective search queries should be specific and targeted, typically using two to four keywords rather than
        broad terms. For extraction tasks ensure you're working with valid URLs and remember this works best after
        identifying pages through search. When utilizing crawl functionality, establish clear objectives
        and appropriate scope boundaries for efficient website exploration.";
    }

    public function provide(): array
    {
        return [
            new TavilyExtractTool($this->key),
            new TavilySearchTool($this->key),
            new TavilyCrawlTool($this->key)
        ];
    }
}
