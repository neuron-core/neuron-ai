<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Tests\Providers\AWS\Stub\BedrockStreamClient;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function array_filter;
use function iterator_to_array;

class BedrockStreamTest extends TestCase
{
    use ConsumesProviderStreams;

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected function provider(array $events): BedrockRuntime
    {
        $provider = new BedrockRuntime(new BedrockStreamClient($events), 'model');
        $provider->setTools([new ToolStub('lookup', 'Look it up'), new ToolStub('now', 'Current time')]);

        return $provider;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function toolBlock(int $index, string $name, string $id, string ...$inputFragments): array
    {
        $events = [['contentBlockStart' => ['contentBlockIndex' => $index, 'start' => ['toolUse' => ['name' => $name, 'toolUseId' => $id]]]]];
        foreach ($inputFragments as $fragment) {
            $events[] = ['contentBlockDelta' => ['contentBlockIndex' => $index, 'delta' => ['toolUse' => ['input' => $fragment]]]];
        }
        $events[] = ['contentBlockStop' => ['contentBlockIndex' => $index]];

        return $events;
    }

    /**
     * @param array<int, mixed> $chunks
     * @return list<ToolArgumentChunk>
     */
    protected static function argumentChunks(array $chunks): array
    {
        return array_values(array_filter($chunks, static fn (mixed $chunk): bool => $chunk instanceof ToolArgumentChunk));
    }

    public function test_tool_input_streamed_in_fragments_is_assembled_and_forwarded(): void
    {
        $provider = $this->provider([
            ['messageStart' => ['role' => 'assistant']],
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Checking.']]],
            ['contentBlockStop' => ['contentBlockIndex' => 0]],
            ...self::toolBlock(1, 'lookup', 'tooluse_A', '{"que', 'ry": "php ', 'ünïcode"}'),
            ['messageStop' => ['stopReason' => 'tool_use']],
            ['metadata' => ['usage' => ['inputTokens' => 30, 'outputTokens' => 12, 'cacheReadInputTokens' => 25]]],
        ]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Find php')));

        $this->assertInstanceOf(TextChunk::class, $chunks[0]);
        $this->assertSame('Checking.', $chunks[0]->content);
        $arguments = self::argumentChunks($chunks);
        $this->assertSame(['{"que', 'ry": "php ', 'ünïcode"}'], array_map(static fn (ToolArgumentChunk $chunk): string => $chunk->delta, $arguments));
        foreach ($arguments as $chunk) {
            $this->assertSame('lookup', $chunk->toolName);
            $this->assertSame('tooluse_A', $chunk->toolCallId);
            $this->assertSame($message->getId(), $chunk->messageId);
        }

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking.', $message->getContent());
        [$call] = $message->getToolCalls();
        $this->assertSame(['lookup', 'tooluse_A', ['query' => 'php ünïcode']], [$call->getName(), $call->getCallId(), $call->getInputs()]);
        $this->assertSame([1], $message->getMetadata('aws_tool_positions'));
        $this->assertSame([30, 12, 25], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens, $message->getUsage()->cachedInputTokens]);
    }

    public function test_parallel_tool_calls_keep_their_own_inputs_and_positions(): void
    {
        $provider = $this->provider([
            ...self::toolBlock(0, 'lookup', 'id-1', '{"query":"a"}'),
            ...self::toolBlock(1, 'lookup', 'id-2', '{"query":', '"b"}'),
            ...self::toolBlock(2, 'now', 'id-3', ''),
            ['messageStop' => ['stopReason' => 'tool_use']],
        ]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Go')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(
            [['lookup', 'id-1', ['query' => 'a']], ['lookup', 'id-2', ['query' => 'b']], ['now', 'id-3', []]],
            array_map(static fn (ToolCall $call): array => [$call->getName(), $call->getCallId(), $call->getInputs()], $message->getToolCalls()),
        );
        $this->assertSame([0, 1, 2], $message->getMetadata('aws_tool_positions'));
        // Empty input fragments produce no argument chunk.
        $this->assertCount(3, self::argumentChunks($chunks));
    }

    public function test_tool_block_left_open_at_the_end_of_the_stream_is_still_collected(): void
    {
        $provider = $this->provider([
            ['contentBlockStart' => ['contentBlockIndex' => 0, 'start' => ['toolUse' => ['name' => 'lookup', 'toolUseId' => 'id-1']]]],
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['toolUse' => ['input' => '{"query":"x"}']]]],
            ['messageStop' => ['stopReason' => 'tool_use']],
        ]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Go')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['query' => 'x'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_tool_use_cut_off_by_the_token_limit_is_not_returned_as_a_tool_call(): void
    {
        $provider = $this->provider([
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Let me look.']]],
            ...self::toolBlock(1, 'lookup', 'id-1', '{"query":"trunc'),
            ['messageStop' => ['stopReason' => 'max_tokens']],
        ]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Go')));

        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Let me look.', $message->getContent());
    }

    public function test_text_only_stream_returns_an_assistant_message(): void
    {
        $provider = $this->provider([
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hel']]],
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'lo']]],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => ['inputTokens' => 3, 'outputTokens' => 2]]],
        ]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Hel', 'lo'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Hello', $message->getContent());
        $this->assertNull($message->getMetadata('aws_tool_positions'));
        $this->assertSame([3, 2, 0], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens, $message->getUsage()->cachedInputTokens]);
    }

    public function test_streamed_tool_use_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider([
            ...self::toolBlock(0, 'shell_exec', 'id-1', '{"cmd":"id"}'),
            ['messageStop' => ['stopReason' => 'tool_use']],
        ]);

        $stream = $provider->stream(new UserMessage('Go'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: shell_exec.');
        iterator_to_array($stream);
    }
}
