<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;

use function json_encode;

/**
 * Adapter for AG-UI Protocol.
 *
 * Implements the streaming event-based protocol defined by AG-UI protocol for real-time
 * agent-frontend interaction. Supports text messages, tool calls, reasoning,
 * and lifecycle events.
 *
 * @see https://docs.ag-ui.com/concepts/events
 */
class AGUIAdapter extends SSEAdapter
{
    protected ?string $runId = null;

    protected ?string $threadId = null;

    protected ?string $currentMessageId = null;

    protected bool $messageStarted = false;

    /** @var array<string, string> Map of tool names to tool call IDs */
    protected array $toolCallIds = [];

    /** @var array<string, bool> Track which tool calls have started */
    protected array $toolCallStarted = [];

    protected bool $reasoningStarted = false;

    protected ?string $reasoningMessageId = null;

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
        // Close reasoning if it was started (transition from reasoning to text)
        foreach ($this->endReasoning() as $event) {
            yield $event;
        }

        // Ensure the message has started
        if (! $this->messageStarted) {
            $this->currentMessageId = $this->generateId('msg');
            $this->messageStarted = true;

            yield $this->sse([
                'type' => 'TEXT_MESSAGE_START',
                'messageId' => $this->currentMessageId,
                'role' => 'assistant',
            ]);
        }

        // Stream content delta
        yield $this->sse([
            'type' => 'TEXT_MESSAGE_CONTENT',
            'messageId' => $this->currentMessageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleReasoning(ReasoningChunk $chunk): iterable
    {
        // Close text message if it was started (transition from text to reasoning)
        foreach ($this->endText() as $event) {
            yield $event;
        }

        if (! $this->reasoningStarted) {
            $this->reasoningStarted = true;
            $this->reasoningMessageId = $chunk->messageId;

            yield $this->sse([
                'type' => 'REASONING_START',
                'messageId' => $chunk->messageId,
            ]);

            yield $this->sse([
                'type' => 'REASONING_MESSAGE_START',
                'messageId' => $chunk->messageId,
                'role' => 'assistant',
            ]);
        }

        yield $this->sse([
            'type' => 'REASONING_MESSAGE_CONTENT',
            'messageId' => $chunk->messageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        // Close any open streams before starting tool calls
        foreach ($this->endReasoning() as $event) {
            yield $event;
        }
        foreach ($this->endText() as $event) {
            yield $event;
        }

        $toolName = $chunk->tool->getName();
        $toolCallId = $this->toolCallIds[$toolName] ?? $this->generateId('call');
        $this->toolCallIds[$toolName] = $toolCallId;

        // Emit ToolCallStart only once per tool
        if (! isset($this->toolCallStarted[$toolCallId])) {
            $this->toolCallStarted[$toolCallId] = true;

            yield $this->sse([
                'type' => 'TOOL_CALL_START',
                'toolCallId' => $toolCallId,
                'toolCallName' => $toolName,
                'parentMessageId' => $this->currentMessageId,
            ]);
        }

        // Stream tool arguments as JSON
        $args = $chunk->tool->getInputs();
        if ($args !== []) {
            yield $this->sse([
                'type' => 'TOOL_CALL_ARGS',
                'toolCallId' => $toolCallId,
                'delta' => json_encode($args),
            ]);
        }

        // Mark tool call arguments as complete
        yield $this->sse([
            'type' => 'TOOL_CALL_END',
            'toolCallId' => $toolCallId,
        ]);
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        $toolName = $chunk->tool->getName();
        $toolCallId = $this->toolCallIds[$toolName] ?? $this->generateId('call');

        // Emit tool result
        yield $this->sse([
            'type' => 'TOOL_CALL_RESULT',
            'toolCallId' => $toolCallId,
            'content' => $chunk->tool->getResult(),
            'role' => 'tool',
        ]);
    }

    public function start(): iterable
    {
        $this->runId = $this->generateId('run');

        yield $this->sse([
            'type' => 'RUN_STARTED',
            'runId' => $this->runId,
            'threadId' => $this->threadId,
        ]);
    }

    /**
     * End the reasoning stream if it is active.
     *
     * @return iterable<string>
     */
    protected function endReasoning(): iterable
    {
        if (! $this->reasoningStarted) {
            return;
        }

        yield $this->sse([
            'type' => 'REASONING_MESSAGE_END',
            'messageId' => $this->reasoningMessageId,
        ]);

        yield $this->sse([
            'type' => 'REASONING_END',
            'messageId' => $this->reasoningMessageId,
        ]);

        $this->reasoningStarted = false;
        $this->reasoningMessageId = null;
    }

    /**
     * End the text message stream if it is active.
     *
     * @return iterable<string>
     */
    protected function endText(): iterable
    {
        if (! $this->messageStarted || $this->currentMessageId === null) {
            return;
        }

        yield $this->sse([
            'type' => 'TEXT_MESSAGE_END',
            'messageId' => $this->currentMessageId,
        ]);

        $this->messageStarted = false;
        $this->currentMessageId = null;
    }

    public function end(): iterable
    {
        foreach ($this->endReasoning() as $event) {
            yield $event;
        }
        foreach ($this->endText() as $event) {
            yield $event;
        }

        // Emit RunFinished event
        if ($this->runId !== null) {
            yield $this->sse([
                'type' => 'RUN_FINISHED',
                'runId' => $this->runId,
                'threadId' => $this->threadId,
            ]);
        }
    }
}
