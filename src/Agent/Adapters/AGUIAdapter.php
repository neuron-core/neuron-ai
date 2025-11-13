<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Adapters;

use NeuronAI\Chat\Messages\Stream\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\TextChunk;
use NeuronAI\Chat\Messages\Stream\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\ToolResultChunk;

/**
 * Adapter for AG-UI Protocol.
 *
 * Implements the streaming event-based protocol defined by AG-UI protocol for real-time
 * agent-frontend interaction. Supports text messages, tool calls, reasoning,
 * and lifecycle events.
 *
 * @see https://docs.ag-ui.com/concepts/events
 */
class AGUIAdapter implements StreamAdapterInterface
{
    protected ?string $runId = null;

    protected ?string $threadId = null;

    protected ?string $currentMessageId = null;

    protected bool $messageStarted = false;

    /** @var array<string, string> Map of tool names to tool call IDs */
    protected array $toolCallIds = [];

    /** @var array<string, bool> Track which tool calls have started */
    protected array $toolCallStarted = [];

    protected ?string $reasoningId = null;

    protected bool $reasoningStarted = false;

    /**
     * @param string|null $threadId Optional thread ID for conversation context
     */
    public function __construct(?string $threadId = null)
    {
        $this->threadId = $threadId ?? $this->generateId('thread');
    }

    public function transform(object $chunk): iterable
    {
        yield from match (true) {
            $chunk instanceof TextChunk => $this->handleText($chunk),
            $chunk instanceof ReasoningChunk => $this->handleReasoning($chunk),
            $chunk instanceof ToolCallChunk => $this->handleToolCall($chunk),
            $chunk instanceof ToolResultChunk => $this->handleToolResult($chunk),
            default => []
        };
    }

    protected function handleText(TextChunk $chunk): iterable
    {
        // Ensure message has started
        if (! $this->messageStarted) {
            $this->currentMessageId = $this->generateId('msg');
            $this->messageStarted = true;

            yield $this->formatEvent([
                'type' => 'TextMessageStart',
                'messageId' => $this->currentMessageId,
                'role' => 'assistant',
                'timestamp' => $this->timestamp(),
            ]);
        }

        // Stream content delta
        yield $this->formatEvent([
            'type' => 'TextMessageContent',
            'messageId' => $this->currentMessageId,
            'delta' => $chunk->content,
            'timestamp' => $this->timestamp(),
        ]);
    }

    protected function handleReasoning(ReasoningChunk $chunk): iterable
    {
        // AG-UI supports reasoning as draft extension
        // We'll emit it as a custom event for now, but this could be
        // specialized into ReasoningMessageStart/Content/End pattern
        if (! $this->reasoningStarted) {
            $this->reasoningId = $this->generateId('reasoning');
            $this->reasoningStarted = true;

            yield $this->formatEvent([
                'type' => 'ReasoningStart',
                'messageId' => $this->reasoningId,
                'timestamp' => $this->timestamp(),
            ]);
        }

        yield $this->formatEvent([
            'type' => 'ReasoningMessageContent',
            'messageId' => $this->reasoningId,
            'delta' => $chunk->content,
            'timestamp' => $this->timestamp(),
        ]);
    }

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        foreach ($chunk->tools as $tool) {
            $toolName = $tool->getName();
            $toolCallId = $this->toolCallIds[$toolName] ?? $this->generateId('call');
            $this->toolCallIds[$toolName] = $toolCallId;

            // Emit ToolCallStart only once per tool
            if (! isset($this->toolCallStarted[$toolCallId])) {
                $this->toolCallStarted[$toolCallId] = true;

                yield $this->formatEvent([
                    'type' => 'ToolCallStart',
                    'toolCallId' => $toolCallId,
                    'toolCallName' => $toolName,
                    'parentMessageId' => $this->currentMessageId,
                    'timestamp' => $this->timestamp(),
                ]);
            }

            // Stream tool arguments as JSON
            $args = $tool->getInputs();
            if (! empty($args)) {
                yield $this->formatEvent([
                    'type' => 'ToolCallArgs',
                    'toolCallId' => $toolCallId,
                    'delta' => \json_encode($args),
                    'timestamp' => $this->timestamp(),
                ]);
            }

            // Mark tool call arguments as complete
            yield $this->formatEvent([
                'type' => 'ToolCallEnd',
                'toolCallId' => $toolCallId,
                'timestamp' => $this->timestamp(),
            ]);
        }
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        foreach ($chunk->tools as $tool) {
            $toolName = $tool->getName();
            $toolCallId = $this->toolCallIds[$toolName] ?? $this->generateId('call');

            // Emit tool result
            yield $this->formatEvent([
                'type' => 'ToolCallResult',
                'toolCallId' => $toolCallId,
                'content' => $tool->getResult(),
                'role' => 'tool',
                'timestamp' => $this->timestamp(),
            ]);
        }
    }

    /**
     * Format event as JSON-encoded Server-Sent Event.
     */
    protected function formatEvent(array $data): string
    {
        return 'data: ' . \json_encode($data) . "\n\n";
    }

    /**
     * Generate unique identifier with prefix.
     */
    protected function generateId(string $prefix): string
    {
        return $prefix . '_' . \uniqid('', true);
    }

    /**
     * Get current timestamp in ISO 8601 format.
     */
    protected function timestamp(): string
    {
        return \date('c');
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    public function start(): iterable
    {
        $this->runId = $this->generateId('run');

        yield $this->formatEvent([
            'type' => 'RunStarted',
            'runId' => $this->runId,
            'threadId' => $this->threadId,
            'timestamp' => $this->timestamp(),
        ]);
    }

    public function end(): iterable
    {
        // Close any open text message
        if ($this->messageStarted && $this->currentMessageId !== null) {
            yield $this->formatEvent([
                'type' => 'TextMessageEnd',
                'messageId' => $this->currentMessageId,
                'timestamp' => $this->timestamp(),
            ]);

            $this->messageStarted = false;
        }

        // Emit RunFinished event
        if ($this->runId !== null) {
            yield $this->formatEvent([
                'type' => 'RunFinished',
                'runId' => $this->runId,
                'timestamp' => $this->timestamp(),
            ]);
        }
    }
}
