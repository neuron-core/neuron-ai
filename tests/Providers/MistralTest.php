<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function count;
use function implode;
use function json_encode;

class MistralTest extends TestCase
{
    protected const TOOL_CALLS = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"q":"paris"}']]];

    protected function provider(string $body): Mistral
    {
        $provider = new Mistral('', 'mistral-large-4-0');
        $provider->setTools([Tool::make('lookup', 'Look it up')]);
        $provider->setHttpClient(new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(status: 200, body: $body)]))
        ));

        return $provider;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function completion(array $message, string $finishReason): string
    {
        return json_encode([
            'choices' => [['index' => 0, 'finish_reason' => $finishReason, 'message' => ['role' => 'assistant', ...$message]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);
    }

    /**
     * The last delta carries the finish reason.
     *
     * @param array<int, array<string, mixed>> $deltas
     */
    protected function sse(array $deltas, string $finishReason): string
    {
        $last = count($deltas) - 1;
        $events = array_map(
            fn (array $delta, int $position): string => 'data: '.json_encode([
                'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $position === $last ? $finishReason : null]],
            ])."\n\n",
            $deltas,
            array_keys($deltas)
        );

        return implode('', $events)."data: [DONE]\n\n";
    }

    /**
     * @return array<string, mixed>
     */
    protected static function thinking(string $text): array
    {
        return ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $text]]];
    }

    public function test_chat_tool_call_with_content_chunks_keeps_the_reasoning(): void
    {
        $message = $this->provider($this->completion([
            'content' => [self::thinking('Need a lookup'), ['type' => 'text', 'text' => 'Let me check.']],
            'tool_calls' => self::TOOL_CALLS,
        ], 'tool_calls'))->chat(new UserMessage('Capital of France?'));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Need a lookup', $message->getReasoning()?->content);
        $this->assertSame('Let me check.', $message->getContent());
        $this->assertSame(['q' => 'paris'], $message->getTools()[0]->getInputs());
    }

    public function test_chat_tool_call_without_content(): void
    {
        $message = $this->provider($this->completion([
            'content' => null,
            'tool_calls' => self::TOOL_CALLS,
        ], 'tool_calls'))->chat(new UserMessage('Capital of France?'));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame([], $message->getContentBlocks());
        $this->assertSame('call_1', $message->getTools()[0]->getCallId());
    }

    public function test_chat_answer_in_content_chunks_keeps_thinking_and_text(): void
    {
        $message = $this->provider($this->completion([
            'content' => [self::thinking('Need a lookup'), ['type' => 'text', 'text' => 'Paris.']],
        ], 'stop'))->chat(new UserMessage('Capital of France?'));

        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Need a lookup', $message->getReasoning()?->content);
        $this->assertSame('Paris.', $message->getContent());
    }

    public function test_reasoning_is_sent_back_as_a_thinking_chunk(): void
    {
        $provider = $this->provider('');
        $message = new AssistantMessage([new ReasoningContent('Need a lookup'), new TextContent('Paris.')]);

        $this->assertSame(
            [self::thinking('Need a lookup'), ['type' => 'text', 'text' => 'Paris.']],
            $provider->messageMapper()->map([$message])[0]['content']
        );
    }

    public function test_stream_keeps_thinking_apart_from_the_answer(): void
    {
        // The phases Mistral documents: thinking lists, a list closing the thinking and opening the answer, plain strings
        $stream = $this->provider($this->sse([
            ['role' => 'assistant', 'content' => ''],
            ['content' => [self::thinking('17 times 20 is 340, ')]],
            ['content' => [self::thinking('plus 51 is 391.')]],
            ['content' => [self::thinking(''), ['type' => 'text', 'text' => '17 * 23']]],
            ['content' => ' = '],
            ['content' => '391.'],
        ], 'stop'))->stream(new UserMessage('What is 17 * 23?'));

        $reasoning = '';
        $text = '';
        foreach ($stream as $chunk) {
            match ($chunk::class) {
                ReasoningChunk::class => $reasoning .= $chunk->content,
                TextChunk::class => $text .= $chunk->content,
                default => null,
            };
        }
        $message = $stream->getReturn();

        $this->assertSame('17 times 20 is 340, plus 51 is 391.', $reasoning);
        $this->assertSame('17 * 23 = 391.', $text);
        $this->assertSame(
            [ReasoningContent::class, TextContent::class],
            array_map(fn (ContentBlockInterface $block): string => $block::class, $message->getContentBlocks())
        );
        $this->assertSame('17 times 20 is 340, plus 51 is 391.', $message->getReasoning()?->content);
        $this->assertSame('17 * 23 = 391.', $message->getContent());
        $this->assertSame('stop', $message->getMetadata('stop_reason'));
    }

    public function test_stream_tool_call_keeps_the_thinking(): void
    {
        $stream = $this->provider($this->sse([
            ['role' => 'assistant', 'content' => ''],
            ['content' => [self::thinking('Need a lookup')]],
            ['tool_calls' => [['index' => 0, ...self::TOOL_CALLS[0]]]],
        ], 'tool_calls'))->stream(new UserMessage('Capital of France?'));

        foreach ($stream as $chunk) {
            $this->assertInstanceOf(ReasoningChunk::class, $chunk);
        }
        $message = $stream->getReturn();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Need a lookup', $message->getReasoning()?->content);
        $this->assertNull($message->getContent());
        $this->assertSame(['q' => 'paris'], $message->getTools()[0]->getInputs());
    }
}
