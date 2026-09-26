<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Ollama;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class OllamaStreamParsingTest extends TestCase
{
    protected function provider(string $body): Ollama
    {
        return (new Ollama('http://localhost:11434/api', 'llama3.2', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        )))->setTools([new ToolStub('lookup')]);
    }

    public function test_streamed_zero_token_is_not_dropped(): void
    {
        $stream = $this->provider(
            '{"message":{"role":"assistant","content":"1"},"done":false}'."\n"
            .'{"message":{"role":"assistant","content":"0"},"done":false}'."\n"
            .'{"message":{"role":"assistant","content":""},"done":true}'."\n"
        )->stream(new UserMessage('Ten?'));

        $chunks = iterator_to_array($stream, false);

        $this->assertCount(2, $chunks);
        $this->assertSame('10', $stream->getReturn()->message()->getContent());
    }

    public function test_error_line_mid_stream_raises_provider_exception(): void
    {
        $stream = $this->provider(
            '{"message":{"role":"assistant","content":"par"},"done":false}'."\n"
            .'{"error":"an error was encountered while running the model: unexpected EOF"}'."\n"
        )->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('unexpected EOF');
        iterator_to_array($stream);
    }

    public function test_streamed_tool_call_keeps_the_usage_of_the_final_line(): void
    {
        $stream = $this->provider(
            '{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"lookup","arguments":{}}}]},"done":false}'."\n"
            .'{"message":{"role":"assistant","content":""},"done":true,"prompt_eval_count":12,"eval_count":5}'."\n"
        )->stream(new UserMessage('Hi'));

        iterator_to_array($stream);

        $message = $stream->getReturn()->message();
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('lookup', $message->getToolCalls()[0]->getName());
        $this->assertSame(12, $message->getUsage()->inputTokens);
        $this->assertSame(5, $message->getUsage()->outputTokens);
    }
}
