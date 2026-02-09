<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMessageMapper;
use NeuronAI\Testing\FakeToolMapper;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class FakeAIProviderTest extends TestCase
{
    public function test_chat_returns_queued_response(): void
    {
        $expected = new AssistantMessage('Hello!');

        $provider = new FakeAIProvider($expected);
        $response = $provider->chat(new UserMessage('Hi'));

        $this->assertSame($expected, $response);
    }

    public function test_chat_returns_responses_sequentially(): void
    {
        $first = new AssistantMessage('First');
        $second = new AssistantMessage('Second');

        $provider = new FakeAIProvider($first, $second);

        $this->assertSame($first, $provider->chat(new UserMessage('1')));
        $this->assertSame($second, $provider->chat(new UserMessage('2')));
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
        $response = $provider->chat(new UserMessage('Hi'));

        $this->assertSame($message, $response);
    }

    public function test_system_prompt_is_stored(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $returned = $provider->systemPrompt('You are helpful.');

        $this->assertSame($provider, $returned);

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame('You are helpful.', $provider->getRecorded()[0]->systemPrompt);
    }

    public function test_tools_are_stored(): void
    {
        $tool = Tool::make('search', 'Search the web')
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
        $provider->chat(new UserMessage('Hello'));

        $records = $provider->getRecorded();
        $this->assertCount(1, $records);
        $this->assertSame('chat', $records[0]->method);
        $this->assertSame('Be helpful', $records[0]->systemPrompt);
        $this->assertCount(1, $records[0]->messages);
        $this->assertNull($records[0]->structuredClass);
    }

    public function test_stream_yields_text_chunks(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello world'));
        $provider->setStreamChunkSize(5);

        $generator = $provider->stream(new UserMessage('Hi'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $this->assertInstanceOf(TextChunk::class, $chunk);
            $chunks[] = $chunk->content;
        }

        $this->assertSame(['Hello', ' worl', 'd'], $chunks);

        $finalMessage = $generator->getReturn();
        $this->assertSame('Hello world', $finalMessage->getContent());
    }

    public function test_stream_records_request(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Streamed'));
        $provider->stream(new UserMessage('Hi'));

        $this->assertSame('stream', $provider->getRecorded()[0]->method);
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

        $finalMessage = $generator->getReturn();
        $this->assertInstanceOf(AssistantMessage::class, $finalMessage);
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

    public function test_message_mapper_returns_fake(): void
    {
        $provider = new FakeAIProvider();
        $this->assertInstanceOf(FakeMessageMapper::class, $provider->messageMapper());
    }

    public function test_tool_payload_mapper_returns_fake(): void
    {
        $provider = new FakeAIProvider();
        $this->assertInstanceOf(FakeToolMapper::class, $provider->toolPayloadMapper());
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
            && $record->systemPrompt === 'Be helpful');
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

    public function test_assert_system_prompt_passes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('You are a weather assistant.');
        $provider->chat(new UserMessage('Hi'));

        $provider->assertSystemPrompt('You are a weather assistant.');
    }

    public function test_assert_system_prompt_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('Something else');
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertSystemPrompt('You are a weather assistant.');
    }

    public function test_assert_tools_configured_passes(): void
    {
        $tool = Tool::make('search', 'Search the web');

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

    public function test_static_make_constructor(): void
    {
        $provider = FakeAIProvider::make(new AssistantMessage('OK'));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertSame('OK', $response->getContent());
    }

    public function test_set_http_client_is_noop(): void
    {
        $provider = new FakeAIProvider();
        $result = $provider->setHttpClient($this->createMock(HttpClientInterface::class));

        $this->assertSame($provider, $result);
    }

    public function test_single_message_is_normalized_to_array(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $record = $provider->getRecorded()[0];
        $this->assertIsArray($record->messages);
        $this->assertCount(1, $record->messages);
    }

    public function test_usage_on_fake_response(): void
    {
        $response = (new AssistantMessage('Hello'))->setUsage(new Usage(10, 20));
        $provider = new FakeAIProvider($response);

        $message = $provider->chat(new UserMessage('Hi'));

        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(20, $message->getUsage()->outputTokens);
    }
}
