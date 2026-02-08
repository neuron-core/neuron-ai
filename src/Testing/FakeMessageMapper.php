<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\MessageMapperInterface;

use function array_map;

class FakeMessageMapper implements MessageMapperInterface
{
    /**
     * @param array<Message> $messages
     * @return array<array<string, mixed>>
     */
    public function map(array $messages): array
    {
        return array_map(
            static fn (Message $message): array => $message->jsonSerialize(),
            $messages
        );
    }
}
