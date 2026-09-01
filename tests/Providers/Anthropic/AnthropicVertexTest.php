<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\AnthropicVertex;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function iterator_to_array;

class AnthropicVertexTest extends TestCase
{
    /**
     * Build an AnthropicVertex provider without performing the OAuth token fetch.
     * The request-building hooks (requestUri/requestBody) are what we test here;
     * the credential flow is identical to GeminiVertex and is not exercised.
     */
    private function provider(string $model, string $location, ?HandlerStack $stack = null): AnthropicVertex
    {
        return new class ($model, $location, $stack) extends AnthropicVertex {
            public function __construct(string $model, string $location, private readonly ?HandlerStack $stack = null)
            {
                $this->model = $model;
                $this->version = 'vertex-2023-10-16';
                $this->max_tokens = 8192;
                $this->parameters = [];
                $this->baseUri = "https://{$location}-aiplatform.googleapis.com/v1/projects/test-project/locations/{$location}/publishers/anthropic/models";

                $this->httpClient = (new GuzzleHttpClient(handler: $this->stack))
                    ->withBaseUri($this->baseUri);
            }
        };
    }

    public function test_chat_uses_vertex_raw_predict_uri_and_body(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"role":"assistant","stop_reason":"end_turn","content":[{"type":"text","text":"Hi there"}],"usage":{"input_tokens":5,"output_tokens":3}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = $this->provider('claude-opus-5', 'us-central1', $stack);
        $provider->chat(new UserMessage('Hi'));

        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0]['request'];

        // Model is located in the URL path with the rawPredict operation suffix.
        $this->assertSame(
            'https://us-central1-aiplatform.googleapis.com/v1/projects/test-project/locations/us-central1/publishers/anthropic/models/claude-opus-5:rawPredict',
            (string) $request->getUri()
        );

        // Model is removed from the body; the API version moves into the body.
        $body = json_decode((string) $request->getBody(), true);
        $this->assertArrayNotHasKey('model', $body);
        $this->assertSame('vertex-2023-10-16', $body['anthropic_version']);
        $this->assertSame(8192, $body['max_tokens']);
        $this->assertSame('Hi', $body['messages'][0]['content'][0]['text']);
    }

    public function test_stream_uses_vertex_stream_raw_predict_uri(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);

        $streamBody = "event: message_start\n";
        $streamBody .= "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"usage\":{\"input_tokens\":2,\"output_tokens\":0}}}\n\n";
        $streamBody .= "event: content_block_start\n";
        $streamBody .= "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n";
        $streamBody .= "event: content_block_stop\n";
        $streamBody .= "data: {\"type\":\"content_block_stop\",\"index\":0}\n\n";
        $streamBody .= "event: message_delta\n";
        $streamBody .= "data: {\"type\":\"message_delta\",\"usage\":{\"output_tokens\":1}}\n\n";

        $mockHandler = new MockHandler([new Response(status: 200, body: $streamBody)]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = $this->provider('claude-opus-5', 'us-central1', $stack);
        iterator_to_array($provider->stream(new UserMessage('Hi')));

        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0]['request'];

        // Streaming uses the streamRawPredict operation suffix.
        $this->assertSame(
            'https://us-central1-aiplatform.googleapis.com/v1/projects/test-project/locations/us-central1/publishers/anthropic/models/claude-opus-5:streamRawPredict',
            (string) $request->getUri()
        );

        $body = json_decode((string) $request->getBody(), true);
        $this->assertArrayNotHasKey('model', $body);
        $this->assertSame('vertex-2023-10-16', $body['anthropic_version']);
        $this->assertTrue($body['stream']);
    }
}
