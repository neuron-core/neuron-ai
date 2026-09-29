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
    /**
     * How far over the window a trim may keep a whole turn rather than cut at the
     * next user message: the window should sit at least this far below the model's limit.
     */
    protected const OVERFLOW_TOLERANCE = 0.05;

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

            $this->normalizeCheckpoints($messages, $trimmedTokens);
        }

        $this->validateAlternation($messages);

        return $messages;
    }

    /**
     * After a head-trim, the remaining checkpoints still carry the provider's
     * original cumulative token values; subtract the trimmed tokens from
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

        // Checkpoint values changed, so the cache is stale
        $this->cachedCount = null;
        $this->cachedLastHash = null;
        $this->cachedCheckpoints = [];
    }

    /**
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
     * @param array<int, array{index: int, tokens: int}> $checkpoints
     * @return array{index: int, tokens: int}
     */
    protected function findTrimPoint(array $messages, array $checkpoints, int $contextWindow): array
    {
        $trimIndex = $this->findTrimIndex($messages, $checkpoints, $contextWindow);

        return $this->adjustTrimIndex($messages, $checkpoints, $trimIndex, $contextWindow);
    }

    /**
     * The smallest cut that fits the window, wherever it lands.
     *
     * @param Message[] $messages
     * @param array<int, array{index: int, tokens: int}> $checkpoints
     */
    protected function findTrimIndex(array $messages, array $checkpoints, int $contextWindow): int
    {
        if ($checkpoints === []) {
            return $this->findTrimIndexByEstimation($messages, $contextWindow);
        }

        $threshold = $this->totalTokens - $contextWindow;

        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint['tokens'] >= $threshold) {
                return $checkpoint['index'] + 1;
            }
        }

        // Tail overflow: trim at the last checkpoint
        return end($checkpoints)['index'] + 1;
    }

    /**
     * A valid chat history must start with a user message. A cut landing inside
     * a turn moves back to that turn's user message, keeping the whole turn, when
     * the kept history stays within the overflow tolerance; otherwise it moves
     * forward to the next user message. The latest turn is kept however large.
     *
     * @param Message[] $messages
     * @param array<int, array{index: int, tokens: int}> $checkpoints
     * @return array{index: int, tokens: int}
     */
    protected function adjustTrimIndex(array $messages, array $checkpoints, int $trimIndex, int $contextWindow): array
    {
        $trimIndex = max(0, min($trimIndex, count($messages) - 1));

        if ($this->isUserMessage($messages[$trimIndex])) {
            return $this->cutAt($messages, $checkpoints, $trimIndex);
        }

        $backward = $this->nearestUserMessage($messages, $trimIndex - 1, -1);
        $forward = $this->nearestUserMessage($messages, $trimIndex + 1, 1);

        if ($backward !== null) {
            $cut = $this->cutAt($messages, $checkpoints, $backward);

            if ($forward === null || $this->totalTokens - $cut['tokens'] <= $contextWindow * (1 + self::OVERFLOW_TOLERANCE)) {
                return $cut;
            }
        }

        // No user message at all: trim nothing
        return $forward === null ? ['index' => 0, 'tokens' => 0] : $this->cutAt($messages, $checkpoints, $forward);
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
     * The cut before a message, with the tokens of everything it drops: the last
     * checkpoint before it, plus the estimate of the messages that follow that checkpoint.
     *
     * @param Message[] $messages
     * @param array<int, array{index: int, tokens: int}> $checkpoints
     * @return array{index: int, tokens: int}
     */
    protected function cutAt(array $messages, array $checkpoints, int $index): array
    {
        $tokens = 0;
        $estimateFrom = 0;

        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint['index'] >= $index) {
                break;
            }

            $tokens = $checkpoint['tokens'];
            $estimateFrom = $checkpoint['index'] + 1;
        }

        for ($i = $estimateFrom; $i < $index; $i++) {
            $tokens += $this->tokenCounter->count($messages[$i]);
        }

        return ['index' => $index, 'tokens' => $tokens];
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message::class === UserMessage::class;
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
