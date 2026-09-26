<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tests\Providers\AWS\Stub\BedrockStreamClient;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

/**
 * chat() maps the provider's finish reason onto the message; stream() must do the same,
 * otherwise callers cannot tell a truncated streamed answer from a complete one.
 */
class StreamStopReasonTest extends TestCase
{
    protected function client(string $body): GuzzleHttpClient
    {
        return new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])));
    }

    protected function stopReasonOf(Generator $stream): ?string
    {
        iterator_to_array($stream);

        return $stream->getReturn()->message()->stopReason();
    }

    public function test_bedrock_stream_keeps_the_stop_reason(): void
    {
        $provider = new BedrockRuntime(new BedrockStreamClient([
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hi']]],
            ['messageStop' => ['stopReason' => 'max_tokens']],
        ]), 'model');

        $this->assertSame('max_tokens', $this->stopReasonOf($provider->stream(new UserMessage('Hi'))));
    }

    public function test_ollama_stream_keeps_the_done_reason(): void
    {
        $provider = new Ollama('http://localhost:11434/api', 'llama3.2', httpClient: $this->client(
            '{"message":{"role":"assistant","content":"Hi"},"done":false}'."\n"
            .'{"message":{"role":"assistant","content":""},"done":true,"done_reason":"length"}'."\n"
        ));

        $this->assertSame('length', $this->stopReasonOf($provider->stream(new UserMessage('Hi'))));
    }

    public function test_cohere_stream_keeps_the_finish_reason(): void
    {
        $provider = new Cohere('key', 'command-a', httpClient: $this->client(
            'data: {"type":"content-delta","index":0,"delta":{"message":{"content":{"text":"Hi"}}}}'."\n\n"
            .'data: {"type":"message-end","delta":{"finish_reason":"MAX_TOKENS"}}'."\n\n"
        ));

        $this->assertSame('MAX_TOKENS', $this->stopReasonOf($provider->stream(new UserMessage('Hi'))));
    }
}
