<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Tavily;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class TavilySearchToolDefaultFiltersTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_a_search_without_time_filters_does_not_restrict_the_results_by_date(): void
    {
        $tool = new TavilySearchTool('tavily-key', httpClient: $this->recordingClient(
            new Response(200, [], json_encode(['answer' => null, 'results' => []])),
        ));

        $tool->setInputs(['search_query' => 'history of the PHP language'])->execute();

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('time_range', $body);
        $this->assertArrayNotHasKey('days', $body);
    }

    public function test_time_filters_chosen_by_the_model_are_forwarded(): void
    {
        $tool = new TavilySearchTool('tavily-key', httpClient: $this->recordingClient(
            new Response(200, [], json_encode(['answer' => null, 'results' => []])),
        ));

        $tool->setInputs(['search_query' => 'php release', 'topic' => 'news', 'time_range' => 'week', 'days' => 3])->execute();

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
        $this->assertSame('week', $body['time_range']);
        $this->assertSame(3, $body['days']);
    }
}
