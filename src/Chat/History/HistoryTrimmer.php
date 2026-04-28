<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;

use function array_reduce;
use function array_slice;
use function count;
use function end;
use function spl_object_hash;
use function sprintf;
use function max;
use function min;

/**
 * Trims chat history to fit within a context window using checkpoint-based calculation.
 * Checkpoints are assistant messages with usage data.
 */
class HistoryTrimmer implements HistoryTrimmerInterface
{
    protected int $totalTokens = 0;

    /** @var array<int, array{index: int, tokens: int}> */
    protected array $cachedCheckpoints = [];
    protected ?int $cachedCount = null;
    protected ?string $cachedLastHash = null;

    public function __construct(
        protected TokenCounter $tokenCounter = new TokenCounter()
    ) {
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    /**
     * @param Message[] $messages
     * @return Message[]
     *
     * @throws ChatHistoryException
     */
    public function trim(array $messages, int $contextWindow): array
    {
        if ($messages === []) {
            $this->totalTokens = 0;
            return [];
        }

        $count = count($messages);
        $hash = spl_object_hash($messages[$count - 1]);

        $checkpoints = $this->getCheckpoints($messages, $count, $hash);
        $this->totalTokens = $this->calculateTotal($messages, $checkpoints, $count);

        if ($this->totalTokens <= $contextWindow) {
            $this->validateAlternation($messages);
            return $messages;
        }

        $trimPoint = $this->findTrimPoint($messages, $checkpoints, $contextWindow);

        if ($trimPoint['index'] > 0) {
            $trimmedTokens = $trimPoint['tokens'];
            $messages = array_slice($messages, $trimPoint['index']);
            $this->totalTokens -= $trimmedTokens;

            // Normalize remaining checkpoint values by subtracting trimmed tokens
            $this->normalizeCheckpoints($messages, $trimmedTokens);
        }

        // Validate alternation after trimming
        $this->validateAlternation($messages);

        return $messages;
    }

    /**
     * Normalize checkpoint values after trimming by subtracting trimmed tokens.
     *
     * When messages are trimmed from the beginning of the history, the remaining
     * checkpoints still have their original cumulative token values from the AI
     * provider. This method adjusts those values to reflect the actual tokens
     * in the remaining messages.
     *
     * @param Message[] $messages The remaining messages after trimming
     * @param int $trimmedTokens The total tokens that were removed
     */
    protected function normalizeCheckpoints(array $messages, int $trimmedTokens): void
    {
        if ($trimmedTokens <= 0) {
            return;
        }

        foreach ($messages as $message) {
            $usage = $message->getUsage();
            if ($usage !== null) {
                // Subtract trimmed tokens from inputTokens (cumulative context)
                // outputTokens stays the same as it represents this message's output
                $normalizedInputTokens = max(0, $usage->inputTokens - $trimmedTokens);
                $message->setUsage(new Usage(
                    $normalizedInputTokens,
                    $usage->outputTokens
                ));
            }
        }

        // Invalidate cache since checkpoint values have changed
        $this->cachedCount = null;
        $this->cachedLastHash = null;
        $this->cachedCheckpoints = [];
    }

    /**
     * Build checkpoints from assistant messages (including tool calls) with usage data.
     * Each checkpoint stores the token count reported by the AI provider at that point.
     *
     * @param Message[] $messages
     * @return array<int, array{index: int, tokens: int}>
     */
    protected function getCheckpoints(array $messages, int $count, string $hash): array
    {
        if ($count === $this->cachedCount && $hash === $this->cachedLastHash) {
            return $this->cachedCheckpoints;
        }

        $checkpoints = [];

        foreach ($messages as $index => $message) {
            if (
                $message instanceof AssistantMessage &&
                ($usage = $message->getUsage()) instanceof Usage
            ) {
                $checkpoints[] = [
                    'index' => $index,
                    'tokens' => $usage->inputTokens + $usage->outputTokens,
                ];
            }
        }

        $this->cachedCount = $count;
        $this->cachedLastHash = $hash;
        $this->cachedCheckpoints = $checkpoints;

        return $checkpoints;
    }

    /**
     * Calculate total tokens from checkpoints or estimation.
     *
     * @param Message[] $messages
     * @param array<int, array{index: int, tokens: int}> $checkpoints
     */
    protected function calculateTotal(array $messages, array $checkpoints, int $count): int
    {
        if ($checkpoints === []) {
            return $this->estimateTokens($messages);
        }

        $lastCheckpoint = end($checkpoints);
        $total = $lastCheckpoint['tokens'];

        // Add estimation for tail (messages after the last checkpoint)
        for ($i = $lastCheckpoint['index'] + 1; $i < $count; $i++) {
            $total += $this->tokenCounter->count($messages[$i]);
        }

        return $total;
    }

    /**
     * Find the trim point (index and tokens to subtract).
     *
     * Adjusts the trim index to ensure the history starts with a UserMessage,
     * using the most accurate token count from checkpoints.
     *
     * @param Message[] $messages
     * @param array<int, array{index: int, tokens: int}> $checkpoints
     * @return array{index: int, tokens: int}
     */
    protected function findTrimPoint(array $messages, array $checkpoints, int $contextWindow): array
    {
        if ($checkpoints === []) {
            $index = $this->findTrimIndexByEstimation($messages, $contextWindow);
            return $this->adjustTrimIndex($messages, $index, 0);
        }

        $threshold = $this->totalTokens - $contextWindow;

        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint['tokens'] >= $threshold) {
                return $this->adjustTrimIndex(
                    $messages,
                    $checkpoint['index'] + 1,
                    $checkpoint['tokens']
                );
            }
        }

        // Tail overflow: trim at the last checkpoint
        $lastCheckpoint = end($checkpoints);
        return $this->adjustTrimIndex(
            $messages,
            $lastCheckpoint['index'] + 1,
            (int)$lastCheckpoint['tokens']
        );
    }

    /**
     * Adjust the trim index to ensure the trimmed history starts with a UserMessage.
     *
     * A valid chat history must start with a user message. This method searches
     * outward from the initial trim index in both directions simultaneously,
     * preferring the closest UserMessage (backward on tie).
     *
     * @param Message[] $messages
     * @return array{index: int, tokens: int}
     */
    protected function adjustTrimIndex(array $messages, int $trimIndex, int $tokens): array
    {
        $count = count($messages);

        if ($count === 0) {
            return ['index' => 0, 'tokens' => 0];
        }

        // Clamp trimIndex to valid bounds
        $trimIndex = max(0, min($trimIndex, $count - 1));

        // Track tokens separately for each direction
        $backwardTokens = $tokens;
        $forwardTokens = $tokens;

        // Search outward from trimIndex in both directions
        $maxOffset = max($trimIndex, $count - 1 - $trimIndex);

        for ($i = 0; $i <= $maxOffset; $i++) {
            // Check backward direction
            $backwardIndex = $trimIndex - $i;
            if ($backwardIndex >= 0) {
                $backwardTokens = $this->updateTokensFromMessage($messages[$backwardIndex], $backwardTokens);

                if ($this->isUserMessage($messages[$backwardIndex])) {
                    return ['index' => $backwardIndex, 'tokens' => $backwardTokens];
                }
            }

            // Check forward direction (skip starting position on first iteration)
            $forwardIndex = $trimIndex + $i;
            if ($i > 0 && $forwardIndex < $count) {
                $forwardTokens = $this->updateTokensFromMessage($messages[$forwardIndex], $forwardTokens);

                if ($this->isUserMessage($messages[$forwardIndex])) {
                    return ['index' => $forwardIndex, 'tokens' => $forwardTokens];
                }
            }
        }

        // No UserMessage found - return safe defaults
        return ['index' => 0, 'tokens' => 0];
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message::class === UserMessage::class;
    }

    protected function updateTokensFromMessage(Message $message, int $tokens): int
    {
        $usage = $message->getUsage();

        if ($usage instanceof Usage) {
            return $usage->inputTokens + $usage->outputTokens;
        }

        return $tokens;
    }

    /**
     * @param Message[] $messages
     */
    protected function findTrimIndexByEstimation(array $messages, int $contextWindow): int
    {
        $runningTotal = 0;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $runningTotal += $this->tokenCounter->count($messages[$i]);
            if ($runningTotal > $contextWindow) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * @param Message[] $messages
     */
    protected function estimateTokens(array $messages): int
    {
        return array_reduce(
            $messages,
            fn (int $carry, Message $message): int => $carry + $this->tokenCounter->count($message),
            0
        );
    }

    /**
     * Validates that messages follow the proper user-assistant alternation.
     *
     * @param Message[] $messages
     * @throws ChatHistoryException
     */
    protected function validateAlternation(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $expectingUser = true;
        $previousMessage = null;

        foreach ($messages as $index => $message) {
            $role = $message->getRole();

            // Tool result messages must follow a tool call message
            if ($message instanceof ToolResultMessage) {
                if (!$previousMessage instanceof ToolCallMessage) {
                    throw new ChatHistoryException(
                        sprintf(
                            'Invalid message sequence: ToolResultMessage at position %d must follow a ToolCallMessage',
                            $index
                        )
                    );
                }
                // After a tool result, we still expect assistant to continue
                $expectingUser = false;
                $previousMessage = $message;
                continue;
            }

            // Tool call messages must come from an assistant role
            if ($message instanceof ToolCallMessage) {
                if ($role !== MessageRole::ASSISTANT->value) {
                    throw new ChatHistoryException(
                        sprintf(
                            'Invalid message sequence: ToolCallMessage at position %d must have ASSISTANT role, got %s',
                            $index,
                            $role
                        )
                    );
                }
                // After tool call, we expect tool result or user
                $expectingUser = true;
                $previousMessage = $message;
                continue;
            }

            // Regular messages must follow the expected alternation
            $expectedRole = $expectingUser ? MessageRole::USER->value : MessageRole::ASSISTANT->value;
            if ($role !== $expectedRole) {
                throw new ChatHistoryException(
                    sprintf(
                        'Invalid message sequence at position %d: expected role %s, got %s',
                        $index,
                        $expectedRole,
                        $role
                    )
                );
            }

            // Toggle expected role for next iteration
            $expectingUser = !$expectingUser;
            $previousMessage = $message;
        }
    }
}
