<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function array_slice;
use function implode;
use function iterator_to_array;

class FakeAIProviderTest extends TestCase
{
    public function test_chat_returns_queued_response(): void
    {
        $expected = new AssistantMessage('Hello!');

        $provider = new FakeAIProvider($expected);
        $response = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertSame($expected, $response);
    }

    public function test_chat_returns_responses_sequentially(): void
    {
        $first = new AssistantMessage('First');
        $second = new AssistantMessage('Second');

        $provider = new FakeAIProvider($first, $second);

        $this->assertSame($first, $provider->chat(new UserMessage('1'))->message());
        $this->assertSame($second, $provider->chat(new UserMessage('2'))->message());
    }

    public function test_empty_queue_throws_exception(): void
    {
        $provider = new FakeAIProvider();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('response queue is empty');

        $provider->chat(new UserMessage('Hi'));
    }

    public function test_add_responses_extends_queue(): void
    {
        $provider = new FakeAIProvider();
        $message = new AssistantMessage('Added later');

        $provider->addResponses($message);
        $response = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertSame($message, $response);
    }

    public function test_system_prompt_is_stored(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $returned = $provider->systemPrompt('You are helpful.');

        $this->assertSame($provider, $returned);

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame('You are helpful.', $provider->getRecorded()[0]->systemPrompt->getContent());
    }

    public function test_tools_are_stored(): void
    {
        $tool = (new ToolStub('search', description: 'Search the web'))
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true));

        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->setTools([$tool]);
        $provider->chat(new UserMessage('Hi'));

        $this->assertCount(1, $provider->getRecorded()[0]->tools);
        $this->assertSame('search', $provider->getRecorded()[0]->tools[0]->getName());
    }

    public function test_chat_records_request(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('Be helpful');
        $first = new UserMessage('Hello');
        $second = new AssistantMessage('Hi there');
        $provider->chat($first, $second);

        $records = $provider->getRecorded();
        $this->assertCount(1, $records);
        $this->assertSame('chat', $records[0]->method);
        $this->assertSame('Be helpful', $records[0]->systemPrompt->getContent());
        $this->assertSame([$first, $second], $records[0]->messages);
        $this->assertSame([], $records[0]->tools);
        $this->assertNull($records[0]->structuredClass);
        $this->assertSame([], $records[0]->structuredSchema);
    }

    public function test_each_record_keeps_the_configuration_of_its_call(): void
    {
        $search = new ToolStub('search', description: 'Search the web');
        $prompt = new SystemMessage('Second prompt');
        $provider = new FakeAIProvider(new AssistantMessage('1'), new AssistantMessage('2'), new AssistantMessage('3'));

        $provider->systemPrompt('First prompt');
        $provider->chat(new UserMessage('a'));
        $provider->systemPrompt($prompt)->setTools([$search]);
        $provider->chat(new UserMessage('b'));
        $provider->systemPrompt(null)->setTools([]);
        $provider->chat(new UserMessage('c'));

        [$first, $second, $third] = $provider->getRecorded();
        $this->assertSame('First prompt', $first->systemPrompt?->getContent());
        $this->assertSame([], $first->tools);
        $this->assertSame($prompt, $second->systemPrompt);
        $this->assertSame([$search], $second->tools);
        $this->assertNull($third->systemPrompt);
        $this->assertSame([], $third->tools);
    }

    public function test_responses_are_consumed_once(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Only'));
        $provider->chat(new UserMessage('a'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('FakeAIProvider response queue is empty.');

        $provider->structured(new UserMessage('b'), 'App\\User', []);
    }

    public function test_stream_yields_text_chunks_of_five_characters_by_default(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello world'));

        $generator = $provider->stream(new UserMessage('Hi'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $this->assertInstanceOf(TextChunk::class, $chunk);
            $chunks[] = $chunk->content;
        }

        $this->assertSame(['Hello', ' worl', 'd'], $chunks);

        $finalMessage = $generator->getReturn();
        $this->assertSame('Hello world', $finalMessage->message()->getContent());
    }

    public function test_stream_records_request(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Streamed'));
        $message = new UserMessage('Hi');
        $provider->stream($message);

        $this->assertSame('stream', $provider->getRecorded()[0]->method);
        $this->assertSame([$message], $provider->getRecorded()[0]->messages);
    }

    public function test_stream_fails_on_call_when_the_queue_is_empty(): void
    {
        $provider = new FakeAIProvider();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('FakeAIProvider response queue is empty.');

        // Never iterated: the failure must not wait for the generator to run.
        $provider->stream(new UserMessage('Hi'));
    }

    public function test_stream_consumes_the_response_before_iteration(): void
    {
        $first = new AssistantMessage('First');
        $second = new AssistantMessage('Second');
        $provider = new FakeAIProvider($first, $second);

        $stream = $provider->stream(new UserMessage('a'));
        $chatResponse = $provider->chat(new UserMessage('b'))->message();
        iterator_to_array($stream, false);

        $this->assertSame($second, $chatResponse);
        $this->assertSame($first, $stream->getReturn()->message());
    }

    public function test_stream_splits_multibyte_text_by_character(): void
    {
        $provider = (new FakeAIProvider(new AssistantMessage('héllo wörld 👋')))->setStreamChunkSize(3);

        $chunks = iterator_to_array($provider->stream(new UserMessage('Hi')), false);

        $this->assertSame(
            ['hél', 'lo ', 'wör', 'ld ', '👋'],
            array_map(static fn (StreamChunk $chunk): string => $chunk->toArray()['content'], $chunks)
        );
        $this->assertContainsOnlyInstancesOf(TextChunk::class, $chunks);
    }

    public function test_every_stream_chunk_carries_the_queued_message_id(): void
    {
        $message = new ToolCallMessage('Checking', [ToolCall::make('search', 'call_1', ['query' => 'php'])]);
        $message->setId('msg_fixed');
        $provider = (new FakeAIProvider($message))->setStreamChunkSize(4);

        $chunks = iterator_to_array($provider->stream(new UserMessage('Hi')), false);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertSame('msg_fixed', $chunk->messageId);
        }
    }

    public function test_stream_tool_call_without_inputs_yields_an_empty_json_object(): void
    {
        $message = new ToolCallMessage(null, [ToolCall::make('now', 'call_1')]);
        $provider = new FakeAIProvider($message);

        $chunks = iterator_to_array($provider->stream(new UserMessage('Hi')), false);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(ToolArgumentChunk::class, $chunks[0]);
        $this->assertSame('{}', $chunks[0]->delta);
        $this->assertSame('call_1', $chunks[0]->toolCallId);
    }

    public function test_stream_with_empty_content(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage());

        $generator = $provider->stream(new UserMessage('Hi'));

        $chunks = [];
        /** @var TextChunk $chunk */
        foreach ($generator as $chunk) {
            $chunks[] = $chunk->content;
        }

        $this->assertEmpty($chunks);

        $finalMessage = $generator->getReturn()->message();
        $this->assertInstanceOf(AssistantMessage::class, $finalMessage);
    }

    public function test_stream_yields_reasoning_chunks_before_text(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage([
            new ReasoningContent('Thinking hard'),
            new TextContent('Answer'),
        ]));
        $provider->setStreamChunkSize(8);

        $chunks = iterator_to_array($provider->stream(new UserMessage('Hi')), false);

        $this->assertSame(
            [
                [ReasoningChunk::class, 'Thinking'],
                [ReasoningChunk::class, ' hard'],
                [TextChunk::class, 'Answer'],
            ],
            array_map(static fn (StreamChunk $chunk): array => [$chunk::class, $chunk->toArray()['content']], $chunks)
        );
        $this->assertSame($chunks[0]->messageId, $chunks[2]->messageId);
    }

    public function test_stream_skips_empty_reasoning_blocks(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage([
            new ReasoningContent('', 'signature'),
            new TextContent('Answer'),
        ]));
        $provider->setStreamChunkSize(10);

        $chunks = iterator_to_array($provider->stream(new UserMessage('Hi')), false);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(TextChunk::class, $chunks[0]);
        $this->assertSame('Answer', $chunks[0]->content);
    }

    public function test_stream_yields_no_chunk_for_media_blocks_but_returns_them(): void
    {
        $message = new AssistantMessage([
            new TextContent('Here it is'),
            new ImageContent('aW1hZ2U=', SourceType::BASE64, 'image/png'),
            new TextContent('Enjoy'),
        ]);
        $provider = (new FakeAIProvider($message))->setStreamChunkSize(20);

        $generator = $provider->stream(new UserMessage('Draw'));
        $chunks = iterator_to_array($generator, false);

        $this->assertSame(
            [[TextChunk::class, 'Here it is'], [TextChunk::class, 'Enjoy']],
            array_map(static fn (StreamChunk $chunk): array => [$chunk::class, $chunk->toArray()['content']], $chunks)
        );
        $this->assertSame($message, $generator->getReturn()->message());
    }

    public function test_stream_yields_tool_argument_chunks_for_tool_calls(): void
    {
        $message = new ToolCallMessage('Let me check', [
            ToolCall::make('search', 'call_1', ['query' => 'php']),
        ]);
        $provider = (new FakeAIProvider($message))->setStreamChunkSize(6);

        $generator = $provider->stream(new UserMessage('Hi'));
        $chunks = iterator_to_array($generator, false);

        $this->assertSame(
            [TextChunk::class, TextChunk::class, ToolArgumentChunk::class, ToolArgumentChunk::class, ToolArgumentChunk::class],
            array_map(static fn (StreamChunk $chunk): string => $chunk::class, $chunks)
        );

        $arguments = array_map(static fn (StreamChunk $chunk): array => $chunk->toArray(), array_slice($chunks, 2));
        $this->assertSame('search', $arguments[0]['toolName']);
        $this->assertSame('call_1', $arguments[0]['toolCallId']);
        $this->assertSame('{"query":"php"}', implode('', array_column($arguments, 'delta')));
        $this->assertSame($message, $generator->getReturn()->message());
    }

    public function test_structured_records_class_and_schema(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name":"Alice"}'));

        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $provider->structured(new UserMessage('Generate'), 'App\\User', $schema);

        $record = $provider->getRecorded()[0];
        $this->assertSame('structured', $record->method);
        $this->assertSame('App\\User', $record->structuredClass);
        $this->assertSame($schema, $record->structuredSchema);
    }

    public function test_get_call_count(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('1'),
            new AssistantMessage('2'),
        );

        $this->assertSame(0, $provider->getCallCount());

        $provider->chat(new UserMessage('a'));
        $this->assertSame(1, $provider->getCallCount());

        $provider->chat(new UserMessage('b'));
        $this->assertSame(2, $provider->getCallCount());
    }

    // ---------------------------------------------------------------
    // Assertion tests
    // ---------------------------------------------------------------

    public function test_assert_call_count_passes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $provider->assertCallCount(1);
        $this->addToAssertionCount(1);
    }

    public function test_assert_call_count_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertCallCount(2);
    }

    public function test_assert_sent_passes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('Be helpful');
        $provider->chat(new UserMessage('Hello world'));

        $provider->assertSent(fn (RequestRecord $record): bool => $record->method === 'chat'
            && $record->systemPrompt?->getContent() === 'Be helpful');
    }

    public function test_assert_sent_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertSent(fn (RequestRecord $r): bool => $r->method === 'stream');
    }

    public function test_assert_nothing_sent_passes(): void
    {
        $provider = new FakeAIProvider();
        $provider->assertNothingSent();
        // If we reach here, the assertion passed
        $this->addToAssertionCount(1);
    }

    public function test_assert_nothing_sent_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertNothingSent();
    }

    public function test_assert_method_call_count(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('1'),
            new AssistantMessage('2'),
            new AssistantMessage('3'),
        );

        $provider->chat(new UserMessage('a'));
        $provider->chat(new UserMessage('b'));
        $provider->stream(new UserMessage('c'));

        $provider->assertMethodCallCount('chat', 2);
        $provider->assertMethodCallCount('stream', 1);
    }

    public function test_assert_method_call_count_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('1'));
        $provider->chat(new UserMessage('a'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Expected 1 'stream' calls, got 0.");

        $provider->assertMethodCallCount('stream', 1);
    }

    public function test_assert_system_prompt_fails_without_calls(): void
    {
        $provider = new FakeAIProvider();
        $provider->systemPrompt('Configured but never sent');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No recorded request had the expected system prompt.');

        $provider->assertSystemPrompt('Configured but never sent');
    }

    public function test_assert_system_prompt_passes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('You are a weather assistant.');
        $provider->chat(new UserMessage('Hi'));

        $provider->assertSystemPrompt('You are a weather assistant.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherSystemPrompts(): iterable
    {
        yield 'different prompt' => ['Something else'];
        yield 'longer prompt containing the expected one' => ['You are a weather assistant. Answer in French.'];
        yield 'different case' => ['you are a weather assistant.'];
    }

    #[DataProvider('otherSystemPrompts')]
    public function test_assert_system_prompt_requires_the_exact_prompt(string $sentPrompt): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt($sentPrompt);
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No recorded request had the expected system prompt.');

        $provider->assertSystemPrompt('You are a weather assistant.');
    }

    public function test_assert_tools_configured_passes(): void
    {
        $tool = new ToolStub('search', description: 'Search the web');

        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->setTools([$tool]);
        $provider->chat(new UserMessage('Hi'));

        $provider->assertToolsConfigured(['search']);
    }

    public function test_assert_tools_configured_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertToolsConfigured(['nonexistent']);
    }

    /**
     * @return iterable<string, array{string[]}>
     */
    public static function mismatchedToolLists(): iterable
    {
        yield 'subset' => [['search']];
        yield 'superset' => [['search', 'weather', 'calendar']];
        yield 'different order' => [['weather', 'search']];
    }

    /**
     * @param string[] $expected
     */
    #[DataProvider('mismatchedToolLists')]
    public function test_assert_tools_configured_requires_the_exact_list(array $expected): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->setTools([new ToolStub('search'), new ToolStub('weather')]);
        $provider->chat(new UserMessage('Hi'));

        $provider->assertToolsConfigured(['search', 'weather']);

        $this->expectException(AssertionFailedError::class);
        $provider->assertToolsConfigured($expected);
    }

    public function test_static_make_constructor(): void
    {
        $provider = FakeAIProvider::make(new AssistantMessage('OK'));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertSame('OK', $response->message()->getContent());
    }

    public function test_set_http_client_is_noop(): void
    {
        $provider = new FakeAIProvider();
        $result = $provider->setHttpClient($this->createMock(HttpClientInterface::class));

        $this->assertSame($provider, $result);
    }

    public function test_structured_normalizes_a_single_message_to_a_list(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{}'), new AssistantMessage('{}'));
        $single = new UserMessage('Hi');
        $list = [new UserMessage('a'), new AssistantMessage('b')];

        $provider->structured($single, 'App\\User', []);
        $provider->structured($list, 'App\\User', []);

        $this->assertSame([$single], $provider->getRecorded()[0]->messages);
        $this->assertSame($list, $provider->getRecorded()[1]->messages);
    }

    public function test_usage_on_fake_response(): void
    {
        $response = (new AssistantMessage('Hello'))->setUsage(new Usage(10, 20));
        $provider = new FakeAIProvider($response);

        $message = $provider->chat(new UserMessage('Hi'));

        $this->assertSame(10, $message->message()->getUsage()->inputTokens);
        $this->assertSame(20, $message->message()->getUsage()->outputTokens);
    }
}
