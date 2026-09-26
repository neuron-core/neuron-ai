<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class DeserializeJsonSerializeOutputTest extends TestCase
{
    public function test_the_raw_json_serialize_array_deserializes(): void
    {
        $message = new UserMessage('Hello');

        $this->assertSame('Hello', (new MessageDeserializer())->deserialize($message->jsonSerialize())->getContent());
    }

    public function test_the_json_decoded_form_deserializes(): void
    {
        $message = new UserMessage('Hello');
        $decoded = json_decode((string) json_encode($message), true);

        $this->assertSame('Hello', (new MessageDeserializer())->deserialize($decoded)->getContent());
    }
}
