<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

/**
 * Stateless storage of conversation messages: every call names its thread, so
 * one instance can serve every conversation of the process. The insertion order
 * of a thread is its order.
 */
interface MessageStoreInterface
{
    /**
     * The active model context of the thread, in insertion order.
     *
     * @return Message[]
     */
    public function loadActive(string $threadId): array;

    /**
     * Every message of the thread, archived included, in insertion order. With a
     * limit, only the $limit most recent messages before $before (the latest when
     * null). A $before unknown to the thread yields no messages.
     *
     * @return Message[]
     */
    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array;

    /**
     * Idempotent: a message whose ID is already stored in the thread is skipped.
     */
    public function append(string $threadId, Message $message): void;

    /**
     * Archive the oldest $count active messages of the thread.
     */
    public function archive(string $threadId, int $count): void;

    /**
     * Remove the thread, archived messages included.
     */
    public function clear(string $threadId): void;
}
