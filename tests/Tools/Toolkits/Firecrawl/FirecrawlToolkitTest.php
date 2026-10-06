<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Firecrawl;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\AssertsApiKeyConfinement;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Firecrawl\FirecrawlScrapeTool;
use NeuronAI\Tools\Toolkits\Firecrawl\FirecrawlSearchTool;
use NeuronAI\Tools\Toolkits\Firecrawl\FirecrawlToolkit;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_combine;
use function array_map;
use function json_decode;
use function json_encode;

class FirecrawlToolkitTest extends TestCase
{
    use AssertsApiKeyConfinement;
    use RecordsHttpRequests;

    public function test_tools_send_their_requests_through_the_injected_client(): void
    {
        $client = $this->recordingClient(
            new Response(200, [], json_encode(['success' => true, 'data' => ['web' => [['title' => 'Title', 'url' => 'https://example.com', 'description' => 'Content']]]])),
            new Response(200, [], json_encode(['success' => true, 'data' => ['markdown' => '# Page']])),
        );
        [$search, $scrape] = FirecrawlToolkit::make('firecrawl-key', httpClient: $client)->tools();

        $search->setInputs(['search_query' => 'neuron ai'])->execute();
        $scrape->setInputs(['url' => 'https://example.com'])->execute();

        $this->assertSame([
            'POST https://api.firecrawl.dev/v2/search',
            'POST https://api.firecrawl.dev/v2/scrape',
        ], $this->sentTargets());
        foreach ($this->sentRequests as $entry) {
            $this->assertSame('Bearer firecrawl-key', $entry['request']->getHeaderLine('Authorization'));
            $this->assertApiKeyTravelsOnlyIn('Authorization', 'firecrawl-key', $entry['request']);
        }
        $this->assertSame('neuron ai', json_decode((string) $this->sentRequests[0]['request']->getBody(), true)['query']);
        $this->assertSame('Content', json_decode((string) $search->getResult(), true)['results'][0]['content']);
        $this->assertSame('# Page', (string) $scrape->getResult());
    }

    public function test_the_toolkit_offers_search_and_url_reader_under_distinct_names(): void
    {
        $tools = FirecrawlToolkit::make('firecrawl-key', $this->recordingClient())->tools();

        $this->assertSame(
            [FirecrawlSearchTool::class => 'web_search', FirecrawlScrapeTool::class => 'url_reader'],
            array_combine(
                array_map(static fn (ToolInterface $tool): string => $tool::class, $tools),
                array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools),
            )
        );
    }
}
