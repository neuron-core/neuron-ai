<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Tavily;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\AssertsApiKeyConfinement;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Tavily\TavilyCrawlTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyExtractTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_combine;
use function array_map;
use function json_decode;
use function json_encode;

class TavilyToolkitTest extends TestCase
{
    use AssertsApiKeyConfinement;
    use RecordsHttpRequests;

    public function test_tools_send_their_requests_through_the_injected_client(): void
    {
        $client = $this->recordingClient(
            new Response(200, [], json_encode(['results' => [['url' => 'https://example.com', 'raw_content' => 'Page']]])),
            new Response(200, [], json_encode(['answer' => 'Answer', 'results' => [['title' => 'Title', 'url' => 'https://example.com', 'content' => 'Content']]])),
            new Response(200, [], json_encode(['base_url' => 'https://example.com', 'results' => []])),
        );
        [$extract, $search, $crawl] = TavilyToolkit::make('tavily-key', httpClient: $client)->tools();

        $extract->setInputs(['url' => 'https://example.com'])->execute();
        $search->setInputs(['search_query' => 'neuron ai'])->execute();
        $crawl->setInputs(['url' => 'https://example.com'])->execute();

        $this->assertSame([
            'POST https://api.tavily.com/extract',
            'POST https://api.tavily.com/search',
            'POST https://api.tavily.com/crawl',
        ], $this->sentTargets());
        foreach ($this->sentRequests as $entry) {
            $this->assertSame('Bearer tavily-key', $entry['request']->getHeaderLine('Authorization'));
            $this->assertApiKeyTravelsOnlyIn('Authorization', 'tavily-key', $entry['request']);
        }
        $this->assertSame('neuron ai', json_decode((string) $this->sentRequests[1]['request']->getBody(), true)['query']);
        $this->assertSame('Answer', json_decode((string) $search->getResult(), true)['answer']);
    }

    public function test_the_toolkit_offers_extract_search_and_crawl_under_distinct_names(): void
    {
        $tools = TavilyToolkit::make('tavily-key', $this->recordingClient())->tools();

        $this->assertSame(
            [TavilyExtractTool::class => 'url_reader', TavilySearchTool::class => 'web_search', TavilyCrawlTool::class => 'url_crawl'],
            array_combine(
                array_map(static fn (ToolInterface $tool): string => $tool::class, $tools),
                array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools),
            )
        );
    }
}
