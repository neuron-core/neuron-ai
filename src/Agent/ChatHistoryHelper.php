<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Agent\Observability\MessageSaved;
use NeuronAI\Agent\Observability\MessageSaving;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;

use function array_map;
use function in_array;
use function is_array;

/**
 * Centralizes chat history writes for agent nodes.
 *
 * The history skips a message it already holds, so a node that runs again
 * writes nothing twice as long as it writes the same message: one taken from
 * the state, the event or a memo. A message built anew on every run has a new
 * identity and is stored again.
 */
trait ChatHistoryHelper
{
    protected function addToChatHistory(ChatHistory $history, AgentState $state, Message|array $messages): void
    {
        $messages = is_array($messages) ? $messages : [$messages];
        $held = array_map(static fn (Message $message): string => $message->getId(), $history->getMessages());

        foreach ($messages as $message) {
            // A replayed write changes nothing, so it reports nothing.
            if (in_array($message->getId(), $held, true)) {
                continue;
            }

            $this->emit(new MessageSaving($message));
            $history->addMessage($message);
            $this->emit(new MessageSaved($message));
        }

        // A replayed write still registers the message on the current cycle's transcript.
        foreach ($messages as $message) {
            $state->addStep($message);
        }
    }
}
