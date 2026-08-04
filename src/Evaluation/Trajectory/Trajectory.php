<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Trajectory;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolInterface;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_values;
use function count;
use function end;
use function implode;
use function json_decode;
use function json_encode;

/**
 * The recorded evaluation subject: a read-only view over the original typed
 * chat messages (ADR 0007). No parallel data schema — accessors answer
 * evaluation questions directly from the framework's own Message and Tool
 * objects, folding the append-only approval log (pending snapshot on the
 * tool_call message, final outcome on the following tool_call_result — ADR
 * 0006) into one entry per call. You run a Conversation; you evaluate its
 * Trajectory.
 */
class Trajectory
{
    /**
     * @param Message[] $messages
     */
    protected function __construct(
        protected readonly array $messages,
    ) {
    }

    /**
     * Public seam: any chat message list, however produced, can be wrapped and
     * evaluated — the Conversation runner is sugar over this same method.
     *
     * @param Message[] $messages
     */
    public static function fromMessages(array $messages): self
    {
        return new self(array_values($messages));
    }

    public static function fromChatHistory(ChatHistoryInterface $chatHistory): self
    {
        return self::fromMessages($chatHistory->getMessages());
    }

    /**
     * The original messages, full fidelity — content blocks, metadata, usage.
     *
     * @return Message[]
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * One entry per tool call, in execution order, optionally filtered by tool
     * name. Where a call has both a pending snapshot (on the tool_call message)
     * and a final outcome (on the tool_call_result message), the final outcome
     * wins — results, approval state, and reject reason come from what actually
     * happened. A suspended tail keeps its pending entries.
     *
     * Note: a tool that never executed (pending or rejected calls) has no
     * result — check ToolInterface::hasResult() before calling getResult().
     *
     * @return ToolInterface[]
     */
    public function toolCalls(?string $name = null): array
    {
        /** @var ToolInterface[] $calls */
        $calls = [];

        // Index (into $calls) of each entry from the last tool_call message,
        // keyed by callId (falling back to tool name for hand-rolled histories
        // without callIds — providers always stamp a unique one).
        /** @var array<string, int> $open */
        $open = [];

        foreach ($this->messages as $message) {
            if ($message instanceof ToolCallMessage) {
                $open = [];
                foreach ($message->getTools() as $tool) {
                    $calls[] = $tool;
                    $open[$tool->getCallId() ?? $tool->getName()] = array_key_last($calls);
                }
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->getTools() as $tool) {
                    $index = $open[$tool->getCallId() ?? $tool->getName()] ?? null;
                    if ($index !== null) {
                        $calls[$index] = $tool;
                    }
                }
                $open = [];
            }
        }

        if ($name === null) {
            return $calls;
        }

        return array_values(array_filter(
            $calls,
            fn (ToolInterface $tool): bool => $tool->getName() === $name
        ));
    }

    public function lastToolCall(?string $name = null): ?ToolInterface
    {
        $calls = $this->toolCalls($name);
        $last = end($calls);

        return $last instanceof ToolInterface ? $last : null;
    }

    /**
     * The user side of the conversation, in order (tool results excluded).
     *
     * @return string[]
     */
    public function userMessages(): array
    {
        $contents = [];
        foreach ($this->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                continue;
            }
            $content = $message->getContent();
            if ($message->getRole() === MessageRole::USER->value && $content !== null && $content !== '') {
                $contents[] = $content;
            }
        }

        return $contents;
    }

    /**
     * The last non-empty assistant message content — the conversation's final
     * answer. Empty string when the conversation produced none (e.g. a
     * suspended tail with no accompanying text).
     */
    public function finalAnswer(): string
    {
        $answer = '';
        foreach ($this->messages as $message) {
            $content = $message->getContent();
            if ($message->getRole() === MessageRole::ASSISTANT->value && $content !== null && $content !== '') {
                $answer = $content;
            }
        }

        return $answer;
    }

    /**
     * Aggregate token usage across the conversation, summed from every message
     * carrying provider-reported usage.
     */
    public function usage(): Usage
    {
        $total = new Usage(0, 0);

        foreach ($this->messages as $message) {
            $usage = $message->getUsage();
            if ($usage instanceof Usage) {
                $total->inputTokens += $usage->inputTokens;
                $total->outputTokens += $usage->outputTokens;
                $total->cachedInputTokens += $usage->cachedInputTokens;
                $total->reasoningTokens += $usage->reasoningTokens;
            }
        }

        return $total;
    }

    /**
     * Canonical human-readable rendering — what transcript-aware judges and the
     * UserSimulator read. Tool calls render with their final outcome (the fold),
     * followed by their result; non-text content blocks render as attachment
     * descriptors so judges know media was part of the exchange.
     */
    public function toTranscript(): string
    {
        $lines = [];
        $calls = $this->toolCalls();
        $position = 0;

        foreach ($this->messages as $message) {
            if ($message instanceof SystemMessage) {
                continue;
            }

            if ($message instanceof ToolCallMessage) {
                $content = $message->getContent();
                if ($content !== null && $content !== '') {
                    $lines[] = "Assistant: {$content}";
                }

                // The folded list holds every call in message order — consume
                // positionally so each renders with its final outcome.
                foreach ($message->getTools() as $ignored) {
                    $lines = [...$lines, ...$this->renderToolCall($calls[$position])];
                    $position++;
                }
                continue;
            }

            if ($message instanceof ToolResultMessage) {
                continue; // already rendered with its tool call
            }

            $role = $message->getRole() === MessageRole::USER->value ? 'User' : 'Assistant';
            $line = "{$role}: " . ($message->getContent() ?? '');

            $descriptors = array_filter(array_map(
                self::describeAttachment(...),
                $message->getContentBlocks()
            ));
            if ($descriptors !== []) {
                $line .= ' [attached: ' . implode(', ', $descriptors) . ']';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    protected function renderToolCall(ToolInterface $tool): array
    {
        $line = "Tool call: {$tool->getName()}(" . json_encode($tool->getInputs()) . ')';

        if ($tool->getApprovalReason() !== null) {
            $line .= " (approval requested: {$tool->getApprovalReason()})";
        }

        $state = $tool->getApprovalState();
        if ($state === ApprovalState::Pending) {
            $line .= ' [pending approval]';
        } elseif ($state === ApprovalState::Approved) {
            $line .= ' [approved]';
        } elseif ($state === ApprovalState::Rejected) {
            $reason = $tool->getRejectReason();
            $line .= ' [rejected' . ($reason !== null ? ": {$reason}" : '') . ']';
        }

        $lines = [$line];

        if ($tool->hasResult()) {
            $lines[] = "Tool result ({$tool->getName()}): {$tool->getResult()}";
        }

        return $lines;
    }

    protected static function describeAttachment(ContentBlockInterface $block): ?string
    {
        if (!$block instanceof ImageContent && !$block instanceof FileContent && !$block instanceof AudioContent && !$block instanceof VideoContent) {
            return null;
        }

        $description = $block->getType()->value;

        if ($block instanceof FileContent && $block->filename !== null) {
            $description .= " \"{$block->filename}\"";
        }

        if ($block->mediaType !== null) {
            $description .= " ({$block->mediaType})";
        }

        return $description;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Serialization reuses the chat-history storage format (jsonSerialize per
     * message, history-style rehydration) so a Trajectory survives the parallel
     * runner's fork boundary even when the live messages carry tools holding
     * closures or connections — they come back as data-only ToolDefinitions,
     * exactly like a stored history.
     *
     * @return array{messages: array<int, array<string, mixed>>}
     */
    public function __serialize(): array
    {
        // The JSON round-trip flattens enums and nested objects to plain values,
        // exactly like a history written to storage — the deserializer's format.
        return [
            'messages' => json_decode((string) json_encode($this->messages), true),
        ];
    }

    /**
     * @param array{messages: array<int, array<string, mixed>>} $data
     */
    public function __unserialize(array $data): void
    {
        $hydrator = new class () extends InMemoryChatHistory {
            /**
             * @param array<int, array<string, mixed>> $messages
             * @return Message[]
             */
            public function hydrate(array $messages): array
            {
                return $this->deserializeMessages($messages);
            }
        };

        $this->messages = $hydrator->hydrate($data['messages']);
    }
}
