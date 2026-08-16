<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Memory;

interface MemoryInterface
{
    /**
     * Recall conversation excerpts from the allowed threads that are relevant
     * to the current query.
     *
     * @param non-empty-list<string> $threadIds
     * @return string[]
     */
    public function recall(array $threadIds, string $query): array;

    /**
     * Store one completed user-assistant exchange.
     */
    public function remember(string $threadId, string $user, string $assistant): void;

    /**
     * Permanently remove every memory associated with a conversation.
     */
    public function forget(string $threadId): void;
}
