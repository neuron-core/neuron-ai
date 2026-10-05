<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use JsonSerializable;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;

use function count;
use function end;
use function sprintf;

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
    public const DEFAULT_CONTEXT_WINDOW = 100_000;

    /**
     * The share of the context window a trim frees. Half the window leaves room
     * for many turns before the first message moves again; 0 cuts just under it.
     */
    public const DEFAULT_HISTORY_TRIM_RATIO = 0.5;

    /**
     * @var Message[]|null the active messages, null until loaded
     */
    protected ?array $messages = null;

    /**
     * @throws ChatHistoryException
     */
    public function __construct(
        protected MessageStoreInterface $store,
        protected string $threadId,
        protected int $contextWindow = self::DEFAULT_CONTEXT_WINDOW,
        protected HistoryTrimmerInterface $trimmer = new HistoryTrimmer(),
        protected float $historyTrimRatio = self::DEFAULT_HISTORY_TRIM_RATIO,
    ) {
        if ($historyTrimRatio < 0 || $historyTrimRatio >= 1) {
            throw new ChatHistoryException(
                sprintf('The history trim ratio must be at least 0 and lower than 1, got %s.', $historyTrimRatio)
            );
        }
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

        // A cut goes on down to the trim ratio. The turns that follow then append
        // without moving the first message, so a provider's prompt cache, which
        // matches a request from its start, keeps serving the conversation.
        if (count($trimmed) < count($messages)) {
            $trimmed = $this->trimmer->trim($trimmed, (int) ($this->contextWindow * (1 - $this->historyTrimRatio)));
        }

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

    /**
     * @return Message[]
     */
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
