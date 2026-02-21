<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function implode;
use function json_decode;

class AnthropicPromptCachingTest extends TestCase
{
    public function test_system_prompt_blocks_with_cache_control(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Response"}],"usage": {"input_tokens": 100,"output_tokens": 20,"cache_creation_input_tokens": 50,"cache_read_input_tokens": 0}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack))
            ->systemPromptBlocks([
                ['type' => 'text', 'text' => 'Static instructions', 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => 'Dynamic context'],
            ]);

        $response = $provider->chat(new UserMessage('Test'));

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $this->assertCount(1, $sentRequests);

        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody(), true);
        $this->assertIsArray($requestBody['system']);
        $this->assertCount(2, $requestBody['system']);
        $this->assertSame('Static instructions', $requestBody['system'][0]['text']);
        $this->assertArrayHasKey('cache_control', $requestBody['system'][0]);
        $this->assertSame('ephemeral', $requestBody['system'][0]['cache_control']['type']);
    }

    public function test_system_prompt_string_still_works(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Response"}],"usage": {"input_tokens": 100,"output_tokens": 20}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack))
            ->systemPrompt('Simple string instructions');

        $response = $provider->chat(new UserMessage('Test'));

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $this->assertCount(1, $sentRequests);

        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody(), true);
        $this->assertIsString($requestBody['system']);
        $this->assertSame('Simple string instructions', $requestBody['system']);
    }

    public function test_usage_tracks_cache_tokens(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Response"}],"usage": {"input_tokens": 100,"output_tokens": 20,"cache_creation_input_tokens": 50,"cache_read_input_tokens": 30}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Test'));
        $usage = $response->getUsage();

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(50, $usage->cacheWriteTokens);
        $this->assertSame(30, $usage->cacheReadTokens);
    }

    public function test_usage_handles_new_cache_creation_object_format(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Response"}],"usage": {"input_tokens": 100,"output_tokens": 20,"cache_creation": {"ephemeral_5m_input_tokens": 30,"ephemeral_1h_input_tokens": 20},"cache_read_input_tokens": 40}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Test'));
        $usage = $response->getUsage();

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(50, $usage->cacheWriteTokens); // 30 + 20
        $this->assertSame(40, $usage->cacheReadTokens);
    }

    public function test_tools_not_cached_by_default(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Response"}],"usage": {"input_tokens": 100,"output_tokens": 20}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $tool = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true));

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack))
            ->setTools([$tool]);

        $provider->chat(new UserMessage('Test'));

        $this->assertCount(1, $sentRequests);
        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody(), true);

        $this->assertArrayHasKey('tools', $requestBody);
        $this->assertCount(1, $requestBody['tools']);

        // Should NOT have cache_control by default
        $this->assertArrayNotHasKey('cache_control', $requestBody['tools'][0]);
    }

    public function test_tools_cached_when_enabled(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Response"}],"usage": {"input_tokens": 100,"output_tokens": 20}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $tool1 = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true));

        $tool2 = Tool::make('calculate', 'Perform calculation')
            ->addProperty(new ToolProperty('expression', PropertyType::STRING, 'Math expression', true));

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack))
            ->withPromptCaching()
            ->setTools([$tool1, $tool2]);

        $provider->chat(new UserMessage('Test'));

        $this->assertCount(1, $sentRequests);
        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody(), true);

        $this->assertArrayHasKey('tools', $requestBody);
        $this->assertCount(2, $requestBody['tools']);

        // Last tool should have cache_control
        $lastTool = $requestBody['tools'][1];
        $this->assertArrayHasKey('cache_control', $lastTool);
        $this->assertSame('ephemeral', $lastTool['cache_control']['type']);

        // First tool should not have cache_control
        $this->assertArrayNotHasKey('cache_control', $requestBody['tools'][0]);
    }

    public function test_stream_captures_cache_metrics(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);

        $streamBody = implode("\n", [
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_123","type":"message","role":"assistant","content":[],"model":"claude-3-7-sonnet-latest","stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":100,"output_tokens":0,"cache_creation":{"ephemeral_5m_input_tokens":50},"cache_read_input_tokens":30}}}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":5}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ]);

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $generator = $provider->stream(new UserMessage('Test'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertNotEmpty($chunks);

        // Get final message from generator
        $message = $generator->getReturn();
        $usage = $message->getUsage();

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(5, $usage->outputTokens);
        $this->assertSame(50, $usage->cacheWriteTokens);
        $this->assertSame(30, $usage->cacheReadTokens);
    }
}
