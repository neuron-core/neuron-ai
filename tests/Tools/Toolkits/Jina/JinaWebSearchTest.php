<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Jina;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Jina\JinaWebSearch;
use PHPUnit\Framework\TestCase;

use function json_decode;

class JinaWebSearchTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_posts_the_query_as_json_with_bearer_authentication(): void
    {
        $tool = new JinaWebSearch('jina-key', httpClient: $this->recordingClient(new Response(200, [], 'results')));

        $tool->setInputs(['search_query' => 'neuron ai'])->execute();

        $this->assertSame(['POST https://s.jina.ai/'], $this->sentTargets());
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('Bearer jina-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('no-content', $request->getHeaderLine('X-Respond-With'));
        $this->assertSame(['q' => 'neuron ai'], json_decode((string) $request->getBody(), true));
    }

    public function test_returns_the_response_body_verbatim(): void
    {
        $body = "[1] Title: Neuron\nURL Source: https://neuron-ai.dev\n";
        $tool = new JinaWebSearch('jina-key', httpClient: $this->recordingClient(new Response(200, [], $body)));

        $this->assertSame($body, $tool('neuron'));
    }

    public function test_a_hostile_query_is_sent_verbatim_as_a_single_json_string_value(): void
    {
        $query = "  \"}, \"q\": \"other\", \"x\": {\"\ncaffè ☕ <script>\n";
        $tool = new JinaWebSearch('jina-key', httpClient: $this->recordingClient(new Response(200, [], 'ok')));

        $tool($query);

        $this->assertSame(['q' => $query], json_decode((string) $this->sentRequests[0]['request']->getBody(), true));
    }

    public function test_the_default_description_mentions_no_topic(): void
    {
        $tool = new JinaWebSearch('jina-key', httpClient: $this->recordingClient());

        $this->assertSame(
            'Use this tool to search the web for additional information if the question is outside the scope of the context you have.',
            $tool->getDescription()
        );
    }

    public function test_the_description_lists_the_configured_topics(): void
    {
        $tool = new JinaWebSearch('jina-key', ['PHP', 'AI agents'], $this->recordingClient());

        $this->assertSame(
            'Use this tool to search the web for additional information about PHP, AI agents, or if the question is outside the scope of the context you have.',
            $tool->getDescription()
        );
    }

    public function test_the_search_query_is_the_only_and_required_input(): void
    {
        $tool = new JinaWebSearch('jina-key', httpClient: $this->recordingClient());

        $this->assertSame(['search_query'], $tool->getRequiredProperties());
        $this->assertCount(1, $tool->getProperties());
    }

    public function test_an_error_response_raises_an_http_exception_without_the_api_key(): void
    {
        $tool = new JinaWebSearch('secret-jina-key', httpClient: $this->recordingClient(new Response(401, [], '{"message":"Unauthorized"}')));

        try {
            $tool('neuron');
            $this->fail('Expected an HttpException for a 401 response.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringContainsString('HTTP 401 error during POST https://s.jina.ai/', $exception->getMessage());
            $this->assertStringNotContainsString('secret-jina-key', $exception->getMessage());
        }
    }
}
