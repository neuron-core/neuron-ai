<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Memory;

interface MemoryInterface
{
    /**
     * Permanently remove every memory associated with a conversation.
     */
    public function forget(string $threadId): void;
}
