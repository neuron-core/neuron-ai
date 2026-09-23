<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
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
