<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Mistral;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class MistralResponseParsingTest extends TestCase
{
    protected function provider(string $body): Mistral
    {
        return (new Mistral('key', 'magistral-medium-latest', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        )))->setTools([new ToolStub('lookup')]);
    }

    public function test_chat_answer_with_content_chunks_keeps_thinking_and_text(): void
    {
        $message = $this->provider('{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":['
            .'{"type":"thinking","thinking":[{"type":"text","text":"Let me think"}]},{"type":"text","text":"Answer"}]}}]}')
            ->chat(new UserMessage('Q'))->message();

        $this->assertSame('Let me think', $message->getReasoning()?->content);
        $this->assertSame('Answer', $message->getContent());
        $this->assertSame('stop', $message->stopReason());
    }

    public function test_chat_tool_calls_with_null_content_are_accepted(): void
    {
        $message = $this->provider('{"choices":[{"index":0,"finish_reason":"tool_calls","message":{"role":"assistant","content":null,'
            .'"tool_calls":[{"id":"c1","type":"function","function":{"name":"lookup","arguments":"{\"q\":\"x\"}"}}]}}]}')
            ->chat(new UserMessage('Q'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('lookup', $message->getToolCalls()[0]->getName());
        $this->assertSame('c1', $message->getToolCalls()[0]->getCallId());
        $this->assertSame(['q' => 'x'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_stream_tool_calls_finished_by_a_separate_chunk_are_not_lost(): void
    {
        $stream = $this->provider(
            'data: {"choices":[{"index":0,"delta":{"tool_calls":[{"id":"c1","index":0,"function":{"name":"lookup","arguments":"{}"}}]},"finish_reason":null}]}'."\n\n"
            .'data: {"choices":[{"index":0,"delta":{"content":""},"finish_reason":"tool_calls"}]}'."\n\n"
            ."data: [DONE]\n\n"
        )->stream(new UserMessage('Q'));

        iterator_to_array($stream, false);

        $message = $stream->getReturn()->message();
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('lookup', $message->getToolCalls()[0]->getName());
        $this->assertSame('c1', $message->getToolCalls()[0]->getCallId());
    }
}
