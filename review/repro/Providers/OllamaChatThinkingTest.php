<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Ollama;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class OllamaChatThinkingTest extends TestCase
{
    protected function provider(string $body): Ollama
    {
        return (new Ollama('http://localhost:11434/api', 'qwen3', parameters: ['think' => true], httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        )))->setTools([new ToolStub('lookup')]);
    }

    public function test_chat_keeps_the_thinking_of_reasoning_models(): void
    {
        $message = $this->provider('{"message":{"role":"assistant","content":"42","thinking":"Let me compute"},"done":true}')
            ->chat(new UserMessage('Answer?'))->message();

        $this->assertSame('42', $message->getContent());
        $this->assertSame('Let me compute', $message->getReasoning()?->content);
    }

    public function test_chat_keeps_the_thinking_that_precedes_a_tool_call(): void
    {
        $message = $this->provider('{"message":{"role":"assistant","content":"","thinking":"I need the tool","tool_calls":[{"function":{"name":"lookup","arguments":{"q":"x"}}}]},"done":true}')
            ->chat(new UserMessage('Answer?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('I need the tool', $message->getReasoning()?->content);
    }

    public function test_chat_without_thinking_has_no_reasoning(): void
    {
        $message = $this->provider('{"message":{"role":"assistant","content":"42","thinking":""},"done":true}')
            ->chat(new UserMessage('Answer?'))->message();

        $this->assertSame('42', $message->getContent());
        $this->assertNull($message->getReasoning());
    }
}
