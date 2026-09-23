<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use JsonSerializable;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;

use function count;
use function end;

use const PHP_INT_MAX;

/**
 * The working context of one conversation for one execution segment: the messages
 * the model sees. It loads the active messages from the store on first use and
 * keeps them inside the context window: messages trimmed out are archived in the
 * store, never deleted. The store is the durable, shareable part; open a new
 * history for every segment.
 */
class ChatHistory implements JsonSerializable
{
    public const DEFAULT_CONTEXT_WINDOW = 50_000;

    /**
     * @var Message[]|null the active messages, null until loaded
     */
    protected ?array $messages = null;

    public function __construct(
        protected MessageStoreInterface $store,
        protected string $threadId,
        protected int $contextWindow = self::DEFAULT_CONTEXT_WINDOW,
        protected HistoryTrimmerInterface $trimmer = new HistoryTrimmer(),
    ) {
    }

    public function getThreadId(): string
    {
        return $this->threadId;
    }

    /**
     * A message already in the context is skipped, so a replayed write never
     * duplicates it. The trim validates the sequence before anything is stored.
     *
     * @throws ChatHistoryException
     */
    public function addMessage(Message $message): self
    {
        $messages = $this->getMessages();

        foreach ($messages as $present) {
            if ($present->getId() === $message->getId()) {
                return $this;
            }
        }

        $messages[] = $message;
        $trimmed = $this->trimmer->trim($messages, $this->contextWindow);

        // Once appended, the store's active messages equal $messages, so archiving
        // the oldest ones removes exactly what the trimmer dropped.
        $this->store->append($this->threadId, $message);

        $archived = count($messages) - count($trimmed);
        if ($archived > 0) {
            $this->store->archive($this->threadId, $archived);
        }

        $this->messages = $trimmed;

        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages ??= $this->store->loadActive($this->threadId);
    }

    /**
     * @throws ChatHistoryException
     */
    public function getLastMessage(): Message
    {
        $messages = $this->getMessages();
        $message = end($messages);

        if ($message === false) {
            throw new ChatHistoryException('No messages in the chat history. It may have been filled with too large single message.');
        }

        return $message;
    }

    public function flushAll(): self
    {
        $this->store->clear($this->threadId);
        $this->messages = [];

        return $this;
    }

    /**
     * @throws ChatHistoryException
     */
    public function calculateTotalUsage(): int
    {
        // An unbounded window never trims: the trimmer only measures.
        $this->trimmer->trim($this->getMessages(), PHP_INT_MAX);

        return $this->trimmer->getTotalTokens();
    }

    /**
     * @return Message[]
     */
    public function jsonSerialize(): array
    {
        return $this->getMessages();
    }
}
