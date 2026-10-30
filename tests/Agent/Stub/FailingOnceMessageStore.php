<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\Message;
use RuntimeException;

/**
 * Fails the first append of the message with the given content, like a store
 * that loses its connection once.
 */
class FailingOnceMessageStore extends InMemoryMessageStore
{
    protected bool $failed = false;

    public function __construct(protected string $content)
    {
    }

    public function append(string $threadId, Message $message): void
    {
        if (!$this->failed && $message->getContent() === $this->content) {
            $this->failed = true;
            throw new RuntimeException('The message store lost its connection.');
        }

        parent::append($threadId, $message);
    }
}
