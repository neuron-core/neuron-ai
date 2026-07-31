<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

use function is_array;

/**
 * Holds the chat history reference for agent nodes and centralizes writes.
 *
 * The history is a live service bound to its own storage, so a write is a
 * side-effecting operation like tool execution or inference — it is wrapped in
 * a durable memo so it runs at most once across crash recovery: on replay the
 * recorded memo is recalled and the write is skipped instead of duplicating
 * the tail. Each call site passes a stable memo name for this reason.
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
     * @throws InspectorException
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

        // Record the messages on the current execution cycle's transcript —
        // outside the memo, so a replayed (skipped) write still registers the
        // message this cycle processed. The transcript is transient state
        // (excluded from durable snapshots, see AgentState).
        if (isset($this->state) && $this->state instanceof AgentState) {
            foreach ($messages as $message) {
                $this->state->addStep($message);
            }
        }
    }
}
