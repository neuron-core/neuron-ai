<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

class InMemoryChatHistory extends AbstractChatHistory
{
    public function setMessages(array $messages): ChatHistoryInterface
    {
        return $this;
    }

    protected function clear(): ChatHistoryInterface
    {
        return $this;
    }
}
