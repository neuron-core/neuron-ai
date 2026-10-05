<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function implode;
use function json_decode;
use function range;

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
            ->systemPrompt(new SystemMessage([
                (new TextContent('Static instructions'))->cache(),
                new TextContent('Dynamic context'),
            ]));

        $response = $provider->chat(new UserMessage('Test'));

        $this->assertInstanceOf(AssistantMessage::class, $response->message());
        $this->assertCount(1, $sentRequests);

        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody(), true);
        $this->assertIsArray($requestBody['system']);
        $this->assertCount(2, $requestBody['system']);
        $this->assertSame('text', $requestBody['system'][0]['type']);
        $this->assertSame('Static instructions', $requestBody['system'][0]['text']);
        $this->assertArrayHasKey('cache_control', $requestBody['system'][0]);
        $this->assertSame('ephemeral', $requestBody['system'][0]['cache_control']['type']);
        $this->assertArrayNotHasKey('cache_control', $requestBody['system'][1]);
    }

    public function test_system_prompt_string_maps_to_single_block(): void
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

        $this->assertInstanceOf(AssistantMessage::class, $response->message());
        $this->assertCount(1, $sentRequests);

        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody(), true);
        $this->assertIsArray($requestBody['system']);
        $this->assertCount(1, $requestBody['system']);
        $this->assertSame('text', $requestBody['system'][0]['type']);
        $this->assertSame('Simple string instructions', $requestBody['system'][0]['text']);
        $this->assertArrayNotHasKey('cache_control', $requestBody['system'][0]);
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
        $usage = $response->message()->getUsage();

        // The prompt is 100 uncached tokens, 50 written to the cache and 30 read from it
        $this->assertSame(180, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(50, $response->message()->getMetadata('cacheWriteTokens'));
        $this->assertSame(30, $response->message()->getMetadata('cacheReadTokens'));
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
        $usage = $response->message()->getUsage();

        // The prompt is 100 uncached tokens, 50 written to the cache and 40 read from it
        $this->assertSame(190, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(50, $response->message()->getMetadata('cacheWriteTokens')); // 30 + 20
        $this->assertSame(40, $response->message()->getMetadata('cacheReadTokens'));
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

        $tool = (new ToolStub('search', description: 'Search the web'))
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

    public function test_cache_control_parameter_enables_automatic_conversation_caching(): void
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

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest', parameters: ['cache_control' => ['type' => 'ephemeral']]))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack))
            ->systemPrompt((new SystemMessage('Static instructions'))->cache());

        $provider->chat(new UserMessage('First question'), new AssistantMessage('Answer'), new UserMessage('Second question'));

        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody(), true);

        // Anthropic places the conversation breakpoint itself: the top-level field is all it needs
        $this->assertSame(['type' => 'ephemeral'], $requestBody['cache_control']);
        $this->assertSame(['type' => 'ephemeral'], $requestBody['system'][0]['cache_control']);
        $this->assertCount(3, $requestBody['messages']);
    }

    public function test_a_prompt_with_more_than_four_cached_blocks_keeps_the_last_four(): void
    {
        $blocks = array_map(fn (int $number): TextContent => (new TextContent("Block {$number}"))->cache(), range(1, 5));

        $body = $this->requestBody(
            (new Anthropic('', 'claude-sonnet-5-5'))->systemPrompt(new SystemMessage($blocks)),
            new UserMessage('Test'),
        );

        $this->assertSame([false, true, true, true, true], $this->breakpoints($body['system']));
    }

    public function test_the_conversation_takes_the_slots_the_instructions_and_tools_leave(): void
    {
        $tool = (new ToolStub('search', description: 'Search the web'))->setParameters(['cache_control' => ['type' => 'ephemeral']]);

        $body = $this->requestBody(
            (new Anthropic('', 'claude-sonnet-5-5'))
                ->systemPrompt((new SystemMessage('Static instructions'))->cache())
                ->setTools([$tool]),
            ...$this->conversationWithCachedQuestions(4),
        );

        $this->assertSame([true], $this->breakpoints($body['system']));
        $this->assertSame([true], $this->breakpoints($body['tools']));
        // Four questions, each followed by its answer but the last: the latest two keep their breakpoint
        $this->assertSame([false, false, false, false, true, false, true], $this->conversationBreakpoints($body));
    }

    public function test_the_automatic_breakpoint_takes_one_of_the_four_slots(): void
    {
        $body = $this->requestBody(
            new Anthropic('', 'claude-sonnet-5-5', parameters: ['cache_control' => ['type' => 'ephemeral']]),
            ...$this->conversationWithCachedQuestions(4),
        );

        $this->assertSame([false, false, true, false, true, false, true], $this->conversationBreakpoints($body));
    }

    public function test_a_tool_argument_named_cache_control_is_not_a_breakpoint(): void
    {
        $tool = (new ToolStub('configure', description: 'Configure a cache'))
            ->addProperty(new ToolProperty('cache_control', PropertyType::STRING, 'The cache policy', true));
        $call = new ToolCall('configure', 'toolu_1', ['cache_control' => 'private']);

        $body = $this->requestBody(
            (new Anthropic('', 'claude-sonnet-5-5'))->setTools([$tool]),
            ...$this->conversationWithCachedQuestions(5),
            ...[new ToolCallMessage(null, [$call]), new ToolResultMessage([(clone $call)->setResult('done')])],
        );

        $this->assertArrayHasKey('cache_control', $body['tools'][0]['input_schema']['properties']);
        $this->assertSame(['cache_control' => 'private'], $body['messages'][9]['content'][0]['input']);
    }

    /**
     * Questions that each carry a cache marker, every one but the last already answered.
     *
     * @return Message[]
     */
    protected function conversationWithCachedQuestions(int $questions): array
    {
        $messages = [];

        for ($number = 1; $number <= $questions; $number++) {
            $messages[] = new UserMessage((new TextContent("Question {$number}"))->cache());
            $messages[] = new AssistantMessage("Answer {$number}");
        }

        return array_slice($messages, 0, -1);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return bool[]
     */
    protected function breakpoints(array $blocks): array
    {
        return array_map(fn (array $block): bool => isset($block['cache_control']), $blocks);
    }

    /**
     * @param array<string, mixed> $body
     * @return bool[] whether each message's only block carries a breakpoint
     */
    protected function conversationBreakpoints(array $body): array
    {
        return array_map(fn (array $message): bool => isset($message['content'][0]['cache_control']), $body['messages']);
    }

    /**
     * @return array<string, mixed> the body of the request the provider sends for these messages
     */
    protected function requestBody(AIProviderInterface $provider, Message ...$messages): array
    {
        $sentRequests = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-sonnet-5-5","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Response"}],"usage": {"input_tokens": 100,"output_tokens": 20}}',
            ),
        ]));
        $stack->push(Middleware::history($sentRequests));

        $provider->setHttpClient(new GuzzleHttpClient(handler: $stack))->chat(...$messages);

        return json_decode((string) $sentRequests[0]['request']->getBody(), true);
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
        $message = $generator->getReturn()->message();
        $usage = $message->getUsage();

        // The prompt is 100 uncached tokens, 50 written to the cache and 30 read from it
        $this->assertSame(180, $usage->inputTokens);
        $this->assertSame(5, $usage->outputTokens);
        $this->assertSame(50, $message->getMetadata('cacheWriteTokens'));
        $this->assertSame(30, $message->getMetadata('cacheReadTokens'));
    }
}
