<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\CustomStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StreamEventInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\StreamAdapterException;

use function json_encode;

/**
 * Adapter for the AG-UI streaming protocol (agent-frontend interaction).
 *
 * @see https://docs.ag-ui.com/concepts/events
 */
class AGUIAdapter extends SSEAdapter implements CustomizableStreamAdapterInterface
{
    use MapsStreamEvents;

    protected ?string $currentMessageId = null;

    protected bool $messageStarted = false;

    /** @var array<string, string> Tool name to tool call ID */
    protected array $toolCallIds = [];

    /** @var array<string, bool> */
    protected array $toolCallStarted = [];

    /** @var array<string, bool> */
    protected array $toolCallArgsStreamed = [];

    protected bool $reasoningStarted = false;

    protected ?string $reasoningMessageId = null;

    /**
     * @param string $threadId The conversation's thread ID. Required: an invented id would
     *                         emit protocol events for a conversation the store has never
     *                         heard of, silently corrupting identity downstream.
     * @param string|null $runId Optional run ID, echoed back to the client as required by the protocol
     */
    public function __construct(protected string $threadId, protected ?string $runId = null)
    {
    }

    /**
     * @throws StreamAdapterException
     */
    public function transform(object $chunk): iterable
    {
        [$resolved, $streamEvent] = $this->resolveStreamEvent($chunk);

        if ($resolved) {
            if ($streamEvent instanceof StreamEventInterface) {
                yield from $this->handleStreamEvent($streamEvent);
            }

            return;
        }

        yield from match (true) {
            $chunk instanceof TextChunk => $this->handleText($chunk),
            $chunk instanceof ReasoningChunk => $this->handleReasoning($chunk),
            $chunk instanceof ToolArgumentChunk => $this->handleToolArgument($chunk),
            $chunk instanceof ToolCallChunk => $this->handleToolCall($chunk),
            $chunk instanceof ToolResultChunk => $this->handleToolResult($chunk),
            default => []
        };
    }

    /**
     * @throws StreamAdapterException
     */
    protected function handleStreamEvent(StreamEventInterface $event): iterable
    {
        yield from match (true) {
            $event instanceof StepStartedStreamEvent => $this->handleStepEvent(
                'STEP_STARTED',
                $event->name,
                $event->metadata,
            ),
            $event instanceof StepFinishedStreamEvent => $this->handleStepEvent(
                'STEP_FINISHED',
                $event->name,
                $event->metadata,
            ),
            $event instanceof ActivityStreamEvent => [$this->sse([
                'type' => 'ACTIVITY_SNAPSHOT',
                'messageId' => $event->id,
                'activityType' => $event->type,
                'content' => (object) $event->data,
                'replace' => true,
            ])],
            $event instanceof CustomStreamEvent => [$this->sse([
                'type' => 'CUSTOM',
                'name' => $event->name,
                'value' => $event->value,
            ])],
            default => throw new StreamAdapterException(
                'AG-UI cannot encode stream event ' . $event::class . '.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    protected function handleStepEvent(string $type, string $name, array $metadata): iterable
    {
        $payload = [
            'type' => $type,
            'stepName' => $name,
        ];

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        yield $this->sse($payload);
    }

    protected function handleText(TextChunk $chunk): iterable
    {
        if ($chunk->content === '') {
            return;
        }

        foreach ($this->endReasoning() as $event) {
            yield $event;
        }

        if (! $this->messageStarted) {
            $this->currentMessageId = $this->generateId('msg');
            $this->messageStarted = true;

            yield $this->sse([
                'type' => 'TEXT_MESSAGE_START',
                'messageId' => $this->currentMessageId,
                'role' => 'assistant',
            ]);
        }

        yield $this->sse([
            'type' => 'TEXT_MESSAGE_CONTENT',
            'messageId' => $this->currentMessageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleReasoning(ReasoningChunk $chunk): iterable
    {
        if ($chunk->content === '') {
            return;
        }

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
                'role' => 'reasoning',
            ]);
        }

        yield $this->sse([
            'type' => 'REASONING_MESSAGE_CONTENT',
            'messageId' => $chunk->messageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleToolArgument(ToolArgumentChunk $chunk): iterable
    {
        // Capture the parent message id before closing the text stream resets it
        $parentMessageId = $this->currentMessageId;

        foreach ($this->endReasoning() as $event) {
            yield $event;
        }
        foreach ($this->endText() as $event) {
            yield $event;
        }

        $toolCallId = $chunk->toolCallId
            ?? $this->toolCallIds[$chunk->toolName]
            ?? $this->generateId('call');
        $this->toolCallIds[$chunk->toolName] = $toolCallId;

        if (! isset($this->toolCallStarted[$toolCallId])) {
            $this->toolCallStarted[$toolCallId] = true;

            $event = [
                'type' => 'TOOL_CALL_START',
                'toolCallId' => $toolCallId,
                'toolCallName' => $chunk->toolName,
            ];

            if ($parentMessageId !== null) {
                $event['parentMessageId'] = $parentMessageId;
            }

            yield $this->sse($event);
        }

        $this->toolCallArgsStreamed[$toolCallId] = true;

        yield $this->sse([
            'type' => 'TOOL_CALL_ARGS',
            'toolCallId' => $toolCallId,
            'delta' => $chunk->delta,
        ]);
    }

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        // Capture the parent message id before closing the text stream resets it
        $parentMessageId = $this->currentMessageId;

        foreach ($this->endReasoning() as $event) {
            yield $event;
        }
        foreach ($this->endText() as $event) {
            yield $event;
        }

        $toolName = $chunk->tool->getName();
        $toolCallId = $this->resolveToolCallId($chunk);

        if (! isset($this->toolCallStarted[$toolCallId])) {
            $this->toolCallStarted[$toolCallId] = true;

            $event = [
                'type' => 'TOOL_CALL_START',
                'toolCallId' => $toolCallId,
                'toolCallName' => $toolName,
            ];

            if ($parentMessageId !== null) {
                $event['parentMessageId'] = $parentMessageId;
            }

            yield $this->sse($event);
        }

        // Skip the args when they were already streamed as ToolArgumentChunk deltas
        $args = $chunk->tool->getInputs();
        if ($args !== [] && ! isset($this->toolCallArgsStreamed[$toolCallId])) {
            yield $this->sse([
                'type' => 'TOOL_CALL_ARGS',
                'toolCallId' => $toolCallId,
                'delta' => json_encode($args),
            ]);
        }

        yield $this->sse([
            'type' => 'TOOL_CALL_END',
            'toolCallId' => $toolCallId,
        ]);
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        $toolCallId = $this->resolveToolCallId($chunk);

        yield $this->sse([
            'type' => 'TOOL_CALL_RESULT',
            'toolCallId' => $toolCallId,
            'content' => (string) $chunk->tool->getResult(),
            'role' => 'tool',
            'messageId' => $this->generateId('msg'),
        ]);
    }

    protected function resolveToolCallId(ToolCallChunk|ToolResultChunk $chunk): string
    {
        $toolName = $chunk->tool->getName();
        $toolCallId = $chunk->tool->getCallId()
            ?? $this->toolCallIds[$toolName]
            ?? $this->generateId('call');
        $this->toolCallIds[$toolName] = $toolCallId;

        return $toolCallId;
    }

    public function start(): iterable
    {
        $this->runId ??= $this->generateId('run');

        yield $this->sse([
            'type' => 'RUN_STARTED',
            'runId' => $this->runId,
            'threadId' => $this->threadId,
        ]);
    }

    /**
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

        if ($this->runId !== null) {
            yield $this->sse([
                'type' => 'RUN_FINISHED',
                'threadId' => $this->threadId,
                'runId' => $this->runId,
            ]);
        }
    }
}
