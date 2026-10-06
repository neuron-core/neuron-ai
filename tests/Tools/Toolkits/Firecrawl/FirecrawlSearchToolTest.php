<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Firecrawl;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Firecrawl\FirecrawlSearchTool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class FirecrawlSearchToolTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_posts_the_query_with_only_the_default_source_and_options(): void
    {
        $tool = new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient($this->searchResponse()));

        $tool->setInputs(['search_query' => 'neuron ai'])->execute();

        $this->assertSame(['POST https://api.firecrawl.dev/v2/search'], $this->sentTargets());
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('Bearer firecrawl-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame([
            'sources' => ['web'],
            'limit' => 5,
            'query' => 'neuron ai',
            'origin' => 'neuron-ai',
        ], $this->sentBody());
    }

    public function test_the_news_topic_searches_the_news_source_without_a_time_filter(): void
    {
        $tool = new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient($this->searchResponse()));

        $tool->setInputs(['search_query' => 'php release', 'topic' => 'news', 'time_range' => 'week'])->execute();

        $body = $this->sentBody();
        $this->assertSame(['news'], $body['sources']);
        $this->assertArrayNotHasKey('tbs', $body);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function timeRanges(): array
    {
        return [
            'day' => ['day', 'qdr:d'],
            'week' => ['week', 'qdr:w'],
            'month' => ['month', 'qdr:m'],
            'year' => ['year', 'qdr:y'],
        ];
    }

    #[DataProvider('timeRanges')]
    public function test_the_time_range_chosen_by_the_model_becomes_a_time_based_search(string $timeRange, string $tbs): void
    {
        $tool = new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient($this->searchResponse()));

        $tool->setInputs(['search_query' => 'neuron ai', 'time_range' => $timeRange])->execute();

        $this->assertSame($tbs, $this->sentBody()['tbs']);
    }

    public function test_custom_options_replace_the_defaults(): void
    {
        $tool = (new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient($this->searchResponse())))
            ->withOptions(['limit' => 10, 'scrapeOptions' => ['formats' => ['markdown'], 'onlyMainContent' => true]]);

        $tool('neuron ai');

        $body = $this->sentBody();
        $this->assertSame(10, $body['limit']);
        $this->assertSame(['formats' => ['markdown'], 'onlyMainContent' => true], $body['scrapeOptions']);
    }

    public function test_options_cannot_override_the_query_from_the_model(): void
    {
        $tool = (new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient($this->searchResponse())))
            ->withOptions(['query' => 'injected']);

        $tool('neuron ai');

        $this->assertSame('neuron ai', $this->sentBody()['query']);
    }

    public function test_web_results_are_reduced_to_title_url_and_content(): void
    {
        $tool = new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient(new Response(200, [], json_encode([
            'success' => true,
            'data' => ['web' => [
                ['title' => 'Neuron', 'url' => 'https://neuron-ai.dev', 'description' => 'Docs', 'position' => 1],
                ['title' => 'Repo', 'url' => 'https://github.com/neuron-core', 'description' => 'Code', 'position' => 2, 'markdown' => '# Neuron'],
                ['url' => 'https://example.com', 'position' => 3],
            ]],
            'creditsUsed' => 2,
        ]))));

        $this->assertSame(['results' => [
            ['title' => 'Neuron', 'url' => 'https://neuron-ai.dev', 'content' => 'Docs'],
            ['title' => 'Repo', 'url' => 'https://github.com/neuron-core', 'content' => '# Neuron'],
            ['title' => '', 'url' => 'https://example.com', 'content' => ''],
        ]], $tool('neuron ai'));
    }

    public function test_news_results_are_read_from_the_news_source_with_their_date(): void
    {
        $tool = new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient(new Response(200, [], json_encode([
            'success' => true,
            'data' => ['news' => [
                ['title' => 'PHP 8.5', 'url' => 'https://example.com/php', 'snippet' => 'Released', 'date' => '1 day ago', 'position' => 1],
            ]],
        ]))));

        $this->assertSame(
            ['results' => [['title' => 'PHP 8.5', 'url' => 'https://example.com/php', 'content' => 'Released', 'date' => '1 day ago']]],
            $tool('php release', 'news')
        );
    }

    public function test_a_search_without_matches_returns_no_results(): void
    {
        $tool = new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient(
            new Response(200, [], json_encode(['success' => true, 'data' => []])),
        ));

        $this->assertSame(['results' => []], $tool('nothing'));
    }

    public function test_the_description_lists_the_configured_topics(): void
    {
        $this->assertSame(
            'Use this tool to search the web for additional information if the question is outside the scope of the context you have.',
            (new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient()))->getDescription()
        );
        $this->assertSame(
            'Use this tool to search the web for additional information about PHP, Laravel, or if the question is outside the scope of the context you have.',
            (new FirecrawlSearchTool('firecrawl-key', ['PHP', 'Laravel'], $this->recordingClient()))->getDescription()
        );
    }

    public function test_the_model_filters_are_constrained_by_enums(): void
    {
        $properties = [];
        foreach ((new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient()))->getProperties() as $property) {
            $this->assertInstanceOf(ToolProperty::class, $property);
            $properties[$property->getName()] = $property->getEnum();
        }

        $this->assertSame([
            'search_query' => [],
            'topic' => ['general', 'news'],
            'time_range' => ['day', 'week', 'month', 'year'],
        ], $properties);
    }

    public function test_an_unknown_time_range_is_returned_to_the_model_without_calling_the_api(): void
    {
        $tool = new FirecrawlSearchTool('firecrawl-key', httpClient: $this->recordingClient());

        $tool->setInputs(['search_query' => 'neuron', 'time_range' => 'hour'])->execute();

        $this->assertStringStartsWith('Parameter "time_range" must be one of', (string) $tool->getResult());
        $this->assertSame([], $this->sentRequests);
    }

    public function test_an_error_response_raises_an_http_exception_without_the_api_key(): void
    {
        $tool = new FirecrawlSearchTool('secret-firecrawl-key', httpClient: $this->recordingClient(
            new Response(402, [], '{"success":false,"error":"Payment required to access this resource."}')
        ));

        try {
            $tool('neuron');
            $this->fail('Expected an HttpException for a 402 response.');
        } catch (HttpException $exception) {
            $this->assertSame(402, $exception->response?->statusCode);
            $this->assertStringNotContainsString('secret-firecrawl-key', $exception->getMessage());
        }
    }

    protected function searchResponse(): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'data' => ['web' => []]]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
    }
}
