<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class MessageDeserializerTest extends TestCase
{
    /**
     * @return array<string, array{Message}>
     */
    public static function messages(): array
    {
        $call = new ToolCall('lookup', 'call-1', ['query' => 'neuron']);
        $settled = (new ToolCall('lookup', 'call-1', ['query' => 'neuron']))->setResult('found');

        return [
            'user' => [new UserMessage('Hello')],
            'assistant' => [new AssistantMessage('Hi there')],
            'assistant with usage' => [(new AssistantMessage('Hi there'))->setUsage(new Usage(100, 20, 80, 5))],
            'tool call' => [new ToolCallMessage('Let me check', [$call])],
            'tool result' => [new ToolResultMessage([$settled])],
        ];
    }

    #[DataProvider('messages')]
    public function test_round_trip_keeps_the_message_identity_and_shape(Message $message): void
    {
        $restored = $this->roundTrip($message);

        $this->assertInstanceOf($message::class, $restored);
        $this->assertSame($message->getId(), $restored->getId());
        $this->assertSame($message->jsonSerialize(), $restored->jsonSerialize());
    }

    public function test_tool_result_message_keeps_its_metadata(): void
    {
        $message = new ToolResultMessage([(new ToolCall('lookup', 'call-1'))->setResult('found')]);
        $message->addMetadata('source', 'cache');

        $this->assertSame('cache', $this->roundTrip($message)->getMetadata('source'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function metadataValues(): array
    {
        return [
            'integer' => [3],
            'float' => [0.5],
            'boolean' => [true],
            'nested' => [['tags' => ['php', 'ai'], 'depth' => 2]],
        ];
    }

    #[DataProvider('metadataValues')]
    public function test_any_json_metadata_value_survives_the_round_trip(mixed $value): void
    {
        $restored = $this->roundTrip((new UserMessage('Hello'))->addMetadata('value', $value));

        $this->assertSame($value, $restored->getMetadata('value'));
    }

    public function test_metadata_stored_beside_the_message_fields_still_deserializes(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            '__id' => 'msg_legacy',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'content' => 'Hi']],
            'stop_reason' => 'end_turn',
            'attempt' => 2,
            'meta' => 'custom',
        ]);

        $this->assertSame('msg_legacy', $restored->getId());
        $this->assertSame('end_turn', $restored->getMetadata('stop_reason'));
        $this->assertSame(2, $restored->getMetadata('attempt'));
        $this->assertSame('custom', $restored->getMetadata('meta'));
    }

    public function test_usage_stored_before_cache_and_reasoning_counts_still_deserializes(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'assistant',
            'content' => [['type' => 'text', 'content' => 'Hi']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $this->assertSame(0, $restored->getUsage()->cachedInputTokens);
        $this->assertSame(0, $restored->getUsage()->reasoningTokens);
    }

    public function test_structural_keys_are_not_restored_as_metadata(): void
    {
        $restored = $this->roundTrip(new ToolCallMessage(null, [new ToolCall('lookup', 'call-1')]));

        $this->assertNull($restored->getMetadata('type'));
        $this->assertNull($restored->getMetadata('tools'));
    }

    protected function roundTrip(Message $message): Message
    {
        $stored = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return (new MessageDeserializer())->deserialize($stored);
    }
}
