<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

use function array_filter;
use function array_map;
use function array_shift;
use function array_values;
use function end;
use function in_array;

/**
 * A turn commits in pieces, so one that failed partway left its question, a tool
 * call without a result, or tool results without an answer at the history tail. The entry node
 * of a new run closes that turn before the run's own messages. The closing
 * messages travel with the inbound and commit with it, after the first inference
 * succeeds.
 */
trait ClosesOpenTurn
{
    /**
     * @param Message[] $inbound
     * @return Message[]
     */
    protected function closeOpenTurn(ChatHistory $history, array $inbound): array
    {
        $messages = $history->getMessages();
        $held = array_map(static fn (Message $message): string => $message->getId(), $messages);

        // A message the history already holds, such as a retried question, is not part of the new turn.
        $inbound = array_values(array_filter(
            $inbound,
            static fn (Message $message): bool => !in_array($message->getId(), $held, true),
        ));

        $tail = end($messages);
        $closing = [];

        // A tool result at the head of the inbound answers the calls itself.
        if ($tail instanceof ToolCallMessage) {
            $tail = ($inbound[0] ?? null) instanceof ToolResultMessage
                ? array_shift($inbound)
                : $this->unrecordedResults($tail);
            $closing[] = $tail;
        }

        if ($tail instanceof UserMessage && ($inbound[0] ?? null) instanceof UserMessage) {
            $closing[] = $this->interruptedAnswer();
        }

        return [...$closing, ...$inbound];
    }

    /**
     * The stored call does not say whether it ran: an approved call keeps the
     * pending state it was written with. So each call is copied without its
     * approval state, with the result an interrupted call records.
     */
    protected function unrecordedResults(ToolCallMessage $message): ToolResultMessage
    {
        return new ToolResultMessage(array_map(
            fn (ToolCall $call): ToolCall => (new ToolCall(
                name: $call->getName(),
                callId: $call->getCallId(),
                inputs: $call->getInputs(),
                description: $call->getDescription(),
                deferred: $call->isDeferred(),
            ))->setResult($this->interruptedResult($call)),
            $message->getToolCalls(),
        ));
    }

    protected function interruptedResult(ToolCall $call): string|ToolOutput
    {
        return ToolOutput::error(
            'The call was interrupted before its result was recorded: it may or may not have taken effect.'
        );
    }

    protected function interruptedAnswer(): AssistantMessage
    {
        return new AssistantMessage('My previous response was interrupted before it was completed.');
    }
}
