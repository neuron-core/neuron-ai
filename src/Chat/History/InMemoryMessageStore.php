<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

use function array_slice;
use function array_values;
use function count;
use function min;

/**
 * Keeps threads in process memory, so nothing survives the process.
 */
class InMemoryMessageStore implements MessageStoreInterface
{
    use PaginatesMessages;

    /**
     * Messages keyed by ID, in insertion order, per thread.
     *
     * @var array<string, array<string, Message>>
     */
    protected array $threads = [];

    /**
     * Archiving always takes the oldest active messages, so the archived ones are
     * a prefix of the thread: their number is all there is to record.
     *
     * @var array<string, int>
     */
    protected array $archived = [];

    public function loadActive(string $threadId): array
    {
        return array_slice($this->messages($threadId), $this->archived[$threadId] ?? 0);
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->paginate($this->messages($threadId), $limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $this->threads[$threadId][$message->getId()] ??= $message;
    }

    public function archive(string $threadId, int $count): void
    {
        $this->archived[$threadId] = min(
            count($this->threads[$threadId] ?? []),
            ($this->archived[$threadId] ?? 0) + $count
        );
    }

    public function clear(string $threadId): void
    {
        unset($this->threads[$threadId], $this->archived[$threadId]);
    }

    /**
     * @return Message[]
     */
    protected function messages(string $threadId): array
    {
        return array_values($this->threads[$threadId] ?? []);
    }
}
