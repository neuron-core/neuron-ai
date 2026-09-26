<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Ollama;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\MessageMapper;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class OllamaHistoryMappingTest extends TestCase
{
    protected function provider(string $body): Ollama
    {
        return (new Ollama('http://localhost:11434/api', 'llama3.2', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        )))->setTools([new ToolStub('lookup')]);
    }

    public function test_tool_call_message_round_trips_its_tool_calls(): void
    {
        $message = $this->provider('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"lookup","arguments":{"q":"rome"}}}]},"done":true}')
            ->chat(new UserMessage('Where?'))->message();

        $mapped = (new MessageMapper())->map([$message]);

        $this->assertSame('lookup', $mapped[0]['tool_calls'][0]['function']['name'] ?? null);
        $this->assertSame(['q' => 'rome'], $mapped[0]['tool_calls'][0]['function']['arguments'] ?? null);
    }

    public function test_reasoning_is_not_replayed_as_assistant_text(): void
    {
        // The block order an Ollama stream produces for a thinking model.
        $answer = new AssistantMessage([new TextContent('42'), new ReasoningContent('Let me compute')]);

        $this->assertSame('42', (new MessageMapper())->map([$answer])[0]['content']);
    }
}
