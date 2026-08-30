<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Memory;

interface MemoryInterface
{
    /**
     * Recall conversation excerpts that are relevant to the current query.
     *
     * @return string[]
     */
    public function recall(string $query): array;

    /**
     * Store one completed user-assistant exchange.
     */
    public function remember(string $threadId, string $user, string $assistant): void;

    /**
     * Permanently remove every memory associated with a conversation.
     */
    public function forget(string $threadId): void;
}
