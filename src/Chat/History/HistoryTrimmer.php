<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;

use function array_reduce;
use function array_slice;
use function count;
use function in_array;
use function intval;

class HistoryTrimmer implements HistoryTrimmerInterface
{
    public function __construct(
        protected TokenCounter $tokenCounter = new TokenCounter()
    ) {
    }

    /**
     * This implementation assumes "distributeUsageData" was already executed on incoming messages
     *
     * @param Message[] $messages
     */
    public function tokenCount(array $messages): int
    {
        return array_reduce($messages, function (int $carry, Message $message): int {
            if (!$message->getUsage() instanceof Usage) {
                return $carry + $this->tokenCounter->count($message);
            }
            return $carry + $message->getUsage()->getTotal();
        }, 0);
    }

    /**
     * @param Message[] $messages
     * @return Message[]
     */
    public function trim(array $messages, int $contextWindow): array
    {
        $tokenCount = $this->tokenCount($messages);

        // Early exit if all messages fit within the token limit
        if ($tokenCount <= $contextWindow) {
            return $this->ensureValidMessageSequence($messages);
        }

        $skipIndex = $this->trimIndex($messages, $contextWindow);

        if ($skipIndex > 0) {
            $messages = array_slice($messages, $skipIndex);
        }

        return $this->ensureValidMessageSequence($messages);
    }

    /**
     * Binary search to find how many messages to skip from the beginning
     */
    protected function trimIndex(array $messages, int $contextWindow): int
    {
        $left = 0;
        $right = count($messages);

        while ($left < $right) {
            $mid = intval(($left + $right) / 2);
            $subset = array_slice($messages, $mid);

            if ($this->tokenCount($subset) <= $contextWindow) {
                // Fits! Try including more messages (skip fewer)
                $right = $mid;
            } else {
                // Doesn't fit! Need to skip more messages
                $left = $mid + 1;
            }
        }

        return $left;
    }

    /**
     * Ensures the message list:
     * 1. Starts with a UserMessage
     * 2. Ends with an AssistantMessage
     * 3. Maintains tool call/result pairs
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function ensureValidMessageSequence(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        // Drop leading tool_call / tool_call_result messages
        $messages = $this->dropLeadingToolMessages($messages);

        if ($messages === []) {
            return [];
        }

        // Ensure it starts with a UserMessage
        $messages = $this->ensureStartsWithUser($messages);

        if ($messages === []) {
            return [];
        }

        // Ensure it ends with an AssistantMessage
        return $this->ensureValidAlternation($messages);
    }

    /**
     * Drops all leading ToolCallMessage / ToolCallResultMessage from the history.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function dropLeadingToolMessages(array $messages): array
    {
        $start = 0;

        foreach ($messages as $index => $message) {
            if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
                $start = $index + 1;
                continue;
            }

            // First non-tool message reached, stop advancing
            break;
        }

        if ($start > 0) {
            return array_slice($messages, $start);
        }

        return $messages;
    }

    /**
     * Ensures the message list starts with a UserMessage.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function ensureStartsWithUser(array $messages): array
    {
        // Find the first UserMessage
        $firstUserIndex = null;

        foreach ($messages as $index => $message) {
            if ($message->getRole() === MessageRole::USER->value) {
                $firstUserIndex = $index;
                break;
            }
        }

        if ($firstUserIndex === null) {
            // No UserMessage found
            return [];
        }

        if ($firstUserIndex === 0) {
            return $messages;
        }

        if ($firstUserIndex > 0) {
            // Remove messages before the first user message
            return array_slice($messages, $firstUserIndex);
        }

        return $messages;
    }

    /**
     * Ensures valid alternation between user and assistant messages.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function ensureValidAlternation(array $messages): array
    {
        $result = [];
        $userRoles = [MessageRole::USER->value, MessageRole::DEVELOPER->value];
        $assistantRoles = [MessageRole::ASSISTANT->value, MessageRole::MODEL->value];
        $expectingRoles = $userRoles; // Should start with user

        foreach ($messages as $message) {
            $messageRole = $message->getRole();

            // Tool result messages have a special case - they're user messages
            // but can only follow tool call messages (assistant)
            // This is valid after a ToolCallMessage
            if ($message instanceof ToolResultMessage && ($result !== [] && $result[count($result) - 1] instanceof ToolCallMessage)) {
                $result[] = $message;
                // After the tool result, we expect assistant again
                $expectingRoles = $assistantRoles;
                continue;
            }

            // Check if this message has the expected role
            if (in_array($messageRole, $expectingRoles, true)) {
                $result[] = $message;
                // Toggle the expected role
                $expectingRoles = ($expectingRoles === $userRoles)
                    ? $assistantRoles
                    : $userRoles;
            }
            // If not the expected role, we have an invalid alternation
            // Skip this message to maintain a valid sequence
        }

        return $result;
    }
}
