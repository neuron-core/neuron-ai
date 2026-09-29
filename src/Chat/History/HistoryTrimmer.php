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
use function sprintf;
use function max;
use function min;

/**
 * Trims chat history to fit within a context window. The window budgets the whole
 * request, as the provider measures it: checkpoints are assistant messages with
 * usage, whose input covers the instructions and tools sent with them. The total is
 * the last checkpoint plus an estimate of the later messages, and a cut is priced by
 * the messages it drops, since instructions and tools stay in the next request.
 */
class HistoryTrimmer implements HistoryTrimmerInterface
{
    /**
     * How far over the window a trim may keep a whole turn rather than cut at the
     * next user message: the window should sit at least this far below the model's limit.
     */
    protected const OVERFLOW_TOLERANCE = 0.05;

    protected int $totalTokens = 0;

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

        $checkpoints = $this->getCheckpoints($messages);
        $this->totalTokens = $this->calculateTotal($messages, $checkpoints, count($messages));

        if ($this->totalTokens <= $contextWindow) {
            $this->validateAlternation($messages);
            return $messages;
        }

        $trimPoint = $this->findTrimPoint($messages, $contextWindow);

        if ($trimPoint['index'] > 0) {
            $trimmedTokens = $trimPoint['tokens'];
            $messages = array_slice($messages, $trimPoint['index']);
            $this->totalTokens -= $trimmedTokens;

            $this->normalizeCheckpoints($messages, $trimmedTokens);
        }

        $this->validateAlternation($messages);

        return $messages;
    }

    /**
     * After a head-trim, the remaining checkpoints still carry the provider's
     * original cumulative token values; subtract the dropped messages' tokens from
     * inputTokens (cumulative context) — outputTokens is per-message and stays.
     *
     * @param Message[] $messages The remaining messages after trimming
     */
    protected function normalizeCheckpoints(array $messages, int $trimmedTokens): void
    {
        if ($trimmedTokens <= 0) {
            return;
        }

        foreach ($messages as $message) {
            $usage = $message->getUsage();
            if ($usage !== null) {
                $normalizedInputTokens = max(0, $usage->inputTokens - $trimmedTokens);
                $message->setUsage(new Usage(
                    $normalizedInputTokens,
                    $usage->outputTokens,
                    $usage->cachedInputTokens,
                    $usage->reasoningTokens,
                ));
            }
        }
    }

    /**
     * Each checkpoint stores the token count reported by the AI provider at that point.
     *
     * @param Message[] $messages
     * @return array<int, array{index: int, tokens: int}>
     */
    protected function getCheckpoints(array $messages): array
    {
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

        return $checkpoints;
    }

    /**
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

        // Estimate the tail after the last checkpoint
        for ($i = $lastCheckpoint['index'] + 1; $i < $count; $i++) {
            $total += $this->tokenCounter->count($messages[$i]);
        }

        return $total;
    }

    /**
     * @param Message[] $messages
     * @return array{index: int, tokens: int}
     */
    protected function findTrimPoint(array $messages, int $contextWindow): array
    {
        return $this->adjustTrimIndex($messages, $this->findTrimIndex($messages, $contextWindow), $contextWindow);
    }

    /**
     * The smallest cut that fits the window, wherever it lands: the oldest messages
     * whose own tokens cover the excess. The provider's usage measures the whole
     * request, instructions and tools included, but only messages can be dropped.
     *
     * @param Message[] $messages
     */
    protected function findTrimIndex(array $messages, int $contextWindow): int
    {
        $excess = $this->totalTokens - $contextWindow;
        $dropped = 0;

        foreach ($messages as $index => $message) {
            $dropped += $this->messageTokens($message);

            if ($dropped >= $excess) {
                return $index + 1;
            }
        }

        return count($messages);
    }

    /**
     * A valid chat history must start with a user message. A cut landing inside
     * a turn moves back to that turn's user message, keeping the whole turn, when
     * the kept history stays within the overflow tolerance; otherwise it moves
     * forward to the next user message. The latest turn is kept however large.
     *
     * @param Message[] $messages
     * @return array{index: int, tokens: int}
     */
    protected function adjustTrimIndex(array $messages, int $trimIndex, int $contextWindow): array
    {
        $trimIndex = max(0, min($trimIndex, count($messages) - 1));

        if ($this->isUserMessage($messages[$trimIndex])) {
            return $this->cutAt($messages, $trimIndex);
        }

        $backward = $this->nearestUserMessage($messages, $trimIndex - 1, -1);
        $forward = $this->nearestUserMessage($messages, $trimIndex + 1, 1);

        if ($backward !== null) {
            $cut = $this->cutAt($messages, $backward);

            if ($forward === null || $this->totalTokens - $cut['tokens'] <= $contextWindow * (1 + self::OVERFLOW_TOLERANCE)) {
                return $cut;
            }
        }

        // No user message at all: trim nothing
        return $forward === null ? ['index' => 0, 'tokens' => 0] : $this->cutAt($messages, $forward);
    }

    /**
     * @param Message[] $messages
     */
    protected function nearestUserMessage(array $messages, int $from, int $step): ?int
    {
        for ($index = $from; $index >= 0 && $index < count($messages); $index += $step) {
            if ($this->isUserMessage($messages[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The cut before a message, with the tokens of the messages it drops.
     *
     * @param Message[] $messages
     * @return array{index: int, tokens: int}
     */
    protected function cutAt(array $messages, int $index): array
    {
        $tokens = 0;

        for ($i = 0; $i < $index; $i++) {
            $tokens += $this->messageTokens($messages[$i]);
        }

        return ['index' => $index, 'tokens' => $tokens];
    }

    /**
     * A message's own tokens: exact for an answer the provider measured, whose
     * output tokens carry no instructions or tools, estimated otherwise.
     */
    protected function messageTokens(Message $message): int
    {
        $usage = $message instanceof AssistantMessage ? $message->getUsage() : null;

        return $usage instanceof Usage ? $usage->outputTokens : $this->tokenCounter->count($message);
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message::class === UserMessage::class;
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

            // A tool call must be answered before the conversation moves on: a pure
            // UserMessage can never directly follow a ToolCallMessage (ToolResultMessage
            // was handled above).
            if ($previousMessage instanceof ToolCallMessage && $message instanceof UserMessage) {
                throw new ChatHistoryException(
                    sprintf(
                        'Invalid message sequence at position %d: a UserMessage cannot directly follow a ToolCallMessage',
                        $index
                    )
                );
            }

            if ($message instanceof ToolCallMessage) {
                // Consecutive tool rounds are valid, but a history still opens with the user
                if ($previousMessage === null) {
                    throw new ChatHistoryException(
                        sprintf(
                            'Invalid message sequence at position %d: expected role %s, got %s',
                            $index,
                            MessageRole::USER->value,
                            $role
                        )
                    );
                }

                if ($role !== MessageRole::ASSISTANT->value) {
                    throw new ChatHistoryException(
                        sprintf(
                            'Invalid message sequence: ToolCallMessage at position %d must have ASSISTANT role, got %s',
                            $index,
                            $role
                        )
                    );
                }
                // After a tool call we expect a tool result (a pure user message is
                // blocked above).
                $expectingUser = true;
                $previousMessage = $message;
                continue;
            }

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

            $expectingUser = !$expectingUser;
            $previousMessage = $message;
        }
    }
}
