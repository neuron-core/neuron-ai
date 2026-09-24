<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

use function is_array;

/**
 * Centralizes chat history writes for agent nodes.
 *
 * A history write is a side effect like tool execution, so it is wrapped in a
 * durable memo: on crash-replay the write is skipped instead of duplicating
 * the tail.
 */
trait ChatHistoryHelper
{
    /**
     * @param string $memo Stable memo name identifying this write within the
     *                     node execution (e.g. 'history.inbound').
     */
    protected function addToChatHistory(ChatHistory $history, AgentState $state, Message|array $messages, string $memo): void
    {
        $messages = is_array($messages) ? $messages : [$messages];

        $this->memoize($memo, function () use ($history, $messages): bool {
            foreach ($messages as $message) {
                $this->emit(new MessageSaving($message));
                $history->addMessage($message);
                $this->emit(new MessageSaved($message));
            }

            return true;
        });

        // Outside the memo: a replayed (skipped) write still registers the
        // message on the current cycle's transcript.
        foreach ($messages as $message) {
            $state->addStep($message);
        }
    }
}
