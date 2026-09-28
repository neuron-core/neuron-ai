<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Ollama;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Ollama\MessageMapper;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use stdClass;

class OllamaMessageMapperTest extends TestCase
{
    public function test_tool_call_message_mapping(): void
    {
        $message = new ToolCallMessage(tools: [ToolCall::make('test', description: 'tool with no properties')]);

        $mapper = new MessageMapper();

        $this->assertEquals([[
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['function' => ['name' => 'test', 'arguments' => new stdClass()]],
            ],
        ]], $mapper->map([$message]));
    }

    public function test_a_tool_call_answered_by_ollama_is_sent_back_with_its_calls(): void
    {
        $provider = (new Ollama('http://localhost:11434/api', 'llama3.2', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: '{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"lookup","arguments":{"q":"rome"}}}]},"done":true}')])),
        )))->setTools([new ToolStub('lookup')]);

        $message = $provider->chat(new UserMessage('Where?'))->message();

        $this->assertSame([[
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['function' => ['name' => 'lookup', 'arguments' => ['q' => 'rome']]]],
        ]], (new MessageMapper())->map([$message]));
    }

    public function test_tool_calls_come_from_the_calls_not_from_another_providers_metadata(): void
    {
        // A history started with OpenAI carries its own tool_calls shape, with arguments as a JSON string
        $message = new ToolCallMessage('Looking up', [ToolCall::make('lookup', 'call_1', ['q' => 'rome'])]);
        $message->addMetadata('tool_calls', [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"q":"rome"}']]]);

        $this->assertSame(
            [['function' => ['name' => 'lookup', 'arguments' => ['q' => 'rome']]]],
            (new MessageMapper())->map([$message])[0]['tool_calls']
        );
    }

    public function test_reasoning_is_not_replayed_as_assistant_text(): void
    {
        // The block order an Ollama stream produces for a thinking model.
        $answer = new AssistantMessage([new TextContent('42'), new ReasoningContent('Let me compute')]);

        $this->assertSame('42', (new MessageMapper())->map([$answer])[0]['content']);
    }
}
