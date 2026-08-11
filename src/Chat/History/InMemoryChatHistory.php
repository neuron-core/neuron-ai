<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use function uniqid;

class InMemoryChatHistory extends AbstractChatHistory
{
    public function __construct(
        ?string $threadId = null,
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);

        // Always pre-bound: the self-generated key is this backend's own
        // storage default (it is ephemeral and in-process), never a framework
        // identity fabrication. The Agent adopts it like any pre-bound key.
        $this->setThreadId($threadId ?? uniqid('mem_', true));
    }
}
