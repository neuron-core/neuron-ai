<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;

trait ResolveChatHistory
{
    /**
     * Called on the agent instance.
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->state->setChatHistory($chatHistory);
        return $this;
    }
}
