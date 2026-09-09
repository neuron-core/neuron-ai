<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

use function is_array;

/**
 * Holds the chat history reference for agent nodes and centralizes writes.
 *
 * A history write is a side effect like tool execution, so it is wrapped in a
 * durable memo: on crash-replay the write is skipped instead of duplicating
 * the tail.
 */
trait ChatHistoryHelper
{
    protected ChatHistoryInterface $chatHistory;

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory;
    }

    /**
     * @param string $memo Stable memo name identifying this write within the
     *                     node execution (e.g. 'history.inbound').
     */
    protected function addToChatHistory(Message|array $messages, string $memo): void
    {
        $messages = is_array($messages) ? $messages : [$messages];

        $this->memoize($memo, function () use ($messages): bool {
            foreach ($messages as $message) {
                $this->emit(new MessageSaving($message));
                $this->chatHistory->addMessage($message);
                $this->emit(new MessageSaved($message));
            }

            return true;
        });

        // Outside the memo: a replayed (skipped) write still registers the
        // message on the current cycle's transcript.
        if (isset($this->state) && $this->state instanceof AgentState) {
            foreach ($messages as $message) {
                $this->state->addStep($message);
            }
        }
    }
}
