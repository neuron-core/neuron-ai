<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use function uniqid;

class InMemoryChatHistory extends AbstractChatHistory
{
    public function __construct(
        protected ?string $threadId = null,
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);
    }

    public function getThreadId(): string
    {
        return $this->threadId ??= uniqid('mem_', true);
    }
}
