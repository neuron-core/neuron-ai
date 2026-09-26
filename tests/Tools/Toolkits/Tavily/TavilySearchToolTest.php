<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Tavily;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class TavilySearchToolTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_posts_the_query_with_default_filters_and_options(): void
    {
        $tool = new TavilySearchTool('tavily-key', httpClient: $this->recordingClient($this->searchResponse()));

        $tool->setInputs(['search_query' => 'neuron ai'])->execute();

        $this->assertSame(['POST https://api.tavily.com/search'], $this->sentTargets());
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('Bearer tavily-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame([
            'topic' => 'general',
            'time_range' => 'day',
            'days' => 7,
            'search_depth' => 'basic',
            'chunks_per_source' => 3,
            'max_results' => 3,
            'query' => 'neuron ai',
        ], $this->sentBody());
    }

    public function test_forwards_the_filters_chosen_by_the_model(): void
    {
        $tool = new TavilySearchTool('tavily-key', httpClient: $this->recordingClient($this->searchResponse()));

        $tool->setInputs(['search_query' => 'rates', 'topic' => 'finance', 'time_range' => 'month', 'days' => '30'])->execute();

        $body = $this->sentBody();
        $this->assertSame('finance', $body['topic']);
        $this->assertSame('month', $body['time_range']);
        $this->assertSame(30, $body['days']);
    }

    public function test_custom_options_replace_the_defaults(): void
    {
        $tool = (new TavilySearchTool('tavily-key', httpClient: $this->recordingClient($this->searchResponse())))
            ->withOptions(['search_depth' => 'advanced', 'include_answer' => true]);

        $tool('neuron ai');

        $body = $this->sentBody();
        $this->assertSame('advanced', $body['search_depth']);
        $this->assertTrue($body['include_answer']);
        $this->assertArrayNotHasKey('max_results', $body);
        $this->assertArrayNotHasKey('chunks_per_source', $body);
    }

    public function test_options_cannot_override_the_query_from_the_model(): void
    {
        $tool = (new TavilySearchTool('tavily-key', httpClient: $this->recordingClient($this->searchResponse())))
            ->withOptions(['query' => 'injected']);

        $tool('neuron ai');

        $this->assertSame('neuron ai', $this->sentBody()['query']);
    }

    public function test_results_are_reduced_to_title_url_and_content(): void
    {
        $tool = new TavilySearchTool('tavily-key', httpClient: $this->recordingClient(new Response(200, [], json_encode([
            'query' => 'neuron ai',
            'answer' => 'A PHP agent framework.',
            'images' => ['https://example.com/logo.png'],
            'response_time' => 1.2,
            'results' => [
                ['title' => 'Neuron', 'url' => 'https://neuron-ai.dev', 'content' => 'Docs', 'score' => 0.9, 'raw_content' => '<html>'],
                ['title' => 'Repo', 'url' => 'https://github.com/neuron-core', 'content' => 'Code', 'score' => 0.5],
            ],
        ]))));

        $this->assertSame([
            'answer' => 'A PHP agent framework.',
            'images' => ['https://example.com/logo.png'],
            'results' => [
                ['title' => 'Neuron', 'url' => 'https://neuron-ai.dev', 'content' => 'Docs'],
                ['title' => 'Repo', 'url' => 'https://github.com/neuron-core', 'content' => 'Code'],
            ],
        ], $tool('neuron ai'));
    }

    public function test_missing_images_default_to_an_empty_list(): void
    {
        $tool = new TavilySearchTool('tavily-key', httpClient: $this->recordingClient(
            new Response(200, [], json_encode(['answer' => null, 'results' => []])),
        ));

        $this->assertSame(['answer' => null, 'images' => [], 'results' => []], $tool('nothing'));
    }

    public function test_the_description_lists_the_configured_topics(): void
    {
        $this->assertSame(
            'Use this tool to search the web for additional information if the question is outside the scope of the context you have.',
            (new TavilySearchTool('tavily-key', httpClient: $this->recordingClient()))->getDescription()
        );
        $this->assertSame(
            'Use this tool to search the web for additional information about PHP, Laravel, or if the question is outside the scope of the context you have.',
            (new TavilySearchTool('tavily-key', ['PHP', 'Laravel'], $this->recordingClient()))->getDescription()
        );
    }

    public function test_the_model_filters_are_constrained_by_enums(): void
    {
        $properties = [];
        foreach ((new TavilySearchTool('tavily-key', httpClient: $this->recordingClient()))->getProperties() as $property) {
            $this->assertInstanceOf(ToolProperty::class, $property);
            $properties[$property->getName()] = $property->getEnum();
        }

        $this->assertSame([
            'search_query' => [],
            'topic' => ['general', 'news', 'finance'],
            'time_range' => ['day', 'week', 'month', 'year'],
            'days' => [],
        ], $properties);
    }

    public function test_a_non_numeric_days_value_is_returned_to_the_model_without_calling_the_api(): void
    {
        $tool = new TavilySearchTool('tavily-key', httpClient: $this->recordingClient());

        $tool->setInputs(['search_query' => 'neuron', 'days' => 'a week'])->execute();

        $this->assertSame('Parameter "days" must be of type integer, string given.', (string) $tool->getResult());
        $this->assertSame([], $this->sentRequests);
    }

    public function test_an_error_response_raises_an_http_exception_without_the_api_key(): void
    {
        $tool = new TavilySearchTool('secret-tavily-key', httpClient: $this->recordingClient(new Response(429, [], '{"detail":"rate limited"}')));

        try {
            $tool('neuron');
            $this->fail('Expected an HttpException for a 429 response.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->response?->statusCode);
            $this->assertStringNotContainsString('secret-tavily-key', $exception->getMessage());
        }
    }

    protected function searchResponse(): Response
    {
        return new Response(200, [], json_encode(['answer' => null, 'results' => []]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
    }
}
