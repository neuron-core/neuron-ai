<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

use function array_reduce;
use function array_slice;
use function count;
use function end;
use function in_array;
use function spl_object_hash;

/**
 * Trims chat history to fit within a context window using checkpoint-based calculation.
 * Checkpoints are assistant messages with usage data.
 */
class HistoryTrimmer implements HistoryTrimmerInterface
{
    protected int $totalTokens = 0;

    /** @var array<int, array{index: int, cumulative: int}> */
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
            return $this->ensureValidMessageSequence($messages);
        }

        $trimPoint = $this->findTrimPoint($messages, $checkpoints, $contextWindow);

        if ($trimPoint['index'] > 0) {
            $messages = array_slice($messages, $trimPoint['index']);
            $this->totalTokens -= $trimPoint['cumulative'];
        }

        $beforeValidation = count($messages);
        $messages = $this->ensureValidMessageSequence($messages);

        // If validation removed additional messages, fall back to estimation
        if (count($messages) < $beforeValidation) {
            $this->totalTokens = $this->estimateTokens($messages);
        }

        return $messages;
    }

    /**
     * Build checkpoints from assistant messages with usage data.
     *
     * @param Message[] $messages
     * @return array<int, array{index: int, cumulative: int}>
     */
    protected function getCheckpoints(array $messages, int $count, string $hash): array
    {
        if ($count === $this->cachedCount && $hash === $this->cachedLastHash) {
            return $this->cachedCheckpoints;
        }

        $checkpoints = [];

        foreach ($messages as $index => $message) {
            if (
                $message->getRole() === MessageRole::ASSISTANT->value &&
                ($usage = $message->getUsage()) !== null
            ) {
                $checkpoints[] = [
                    'index' => $index,
                    'cumulative' => $usage->inputTokens + $usage->outputTokens,
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
     * @param array<int, array{index: int, cumulative: int}> $checkpoints
     */
    protected function calculateTotal(array $messages, array $checkpoints, int $count): int
    {
        if ($checkpoints === []) {
            return $this->estimateTokens($messages);
        }

        $lastCheckpoint = end($checkpoints);
        $total = $lastCheckpoint['cumulative'];

        // Add estimation for tail (messages after last checkpoint)
        for ($i = $lastCheckpoint['index'] + 1; $i < $count; $i++) {
            $total += $this->tokenCounter->count($messages[$i]);
        }

        return $total;
    }

    /**
     * Find the trim point (index and cumulative tokens to subtract).
     *
     * @param Message[] $messages
     * @param array<int, array{index: int, cumulative: int}> $checkpoints
     * @return array{index: int, cumulative: int}
     */
    protected function findTrimPoint(array $messages, array $checkpoints, int $contextWindow): array
    {
        if ($checkpoints === []) {
            $index = $this->findTrimIndexByEstimation($messages, $contextWindow);
            $trimmedTokens = 0;
            for ($i = 0; $i < $index; $i++) {
                $trimmedTokens += $this->tokenCounter->count($messages[$i]);
            }
            return ['index' => $index, 'cumulative' => $trimmedTokens];
        }

        $threshold = $this->totalTokens - $contextWindow;

        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint['cumulative'] >= $threshold) {
                return [
                    'index' => $checkpoint['index'] + 1,
                    'cumulative' => $checkpoint['cumulative'],
                ];
            }
        }

        // Tail overflow: trim at last checkpoint
        $lastCheckpoint = end($checkpoints);
        return [
            'index' => $lastCheckpoint['index'] + 1,
            'cumulative' => $lastCheckpoint['cumulative'],
        ];
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
     * Ensures the message list maintains valid conversation structure.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function ensureValidMessageSequence(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        // Drop leading tool messages
        $start = 0;
        foreach ($messages as $index => $message) {
            if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
                $start = $index + 1;
                continue;
            }
            break;
        }

        if ($start > 0) {
            $messages = array_slice($messages, $start);
        }

        if ($messages === []) {
            return [];
        }

        // Find first user message
        $firstUserIndex = null;
        foreach ($messages as $index => $message) {
            if ($message->getRole() === MessageRole::USER->value) {
                $firstUserIndex = $index;
                break;
            }
        }

        if ($firstUserIndex === null) {
            return [];
        }

        if ($firstUserIndex > 0) {
            $messages = array_slice($messages, $firstUserIndex);
        }

        return $this->ensureValidAlternation($messages);
    }

    /**
     * @param Message[] $messages
     * @return Message[]
     */
    protected function ensureValidAlternation(array $messages): array
    {
        $result = [];
        $userRoles = [MessageRole::USER->value, MessageRole::DEVELOPER->value];
        $assistantRoles = [MessageRole::ASSISTANT->value, MessageRole::MODEL->value];
        $expectingRoles = $userRoles;

        foreach ($messages as $message) {
            $role = $message->getRole();

            if (
                $message instanceof ToolResultMessage &&
                $result !== [] &&
                $result[count($result) - 1] instanceof ToolCallMessage
            ) {
                $result[] = $message;
                $expectingRoles = $assistantRoles;
                continue;
            }

            if (in_array($role, $expectingRoles, true)) {
                $result[] = $message;
                $expectingRoles = ($expectingRoles === $userRoles) ? $assistantRoles : $userRoles;
            }
        }

        return $result;
    }
}
