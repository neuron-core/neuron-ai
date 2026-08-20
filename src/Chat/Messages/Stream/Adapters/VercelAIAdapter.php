<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\CustomStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StreamEventInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\UniqueIdGenerator;

/**
 * Adapter for Vercel AI SDK Data Stream Protocol.
 *
 * @see https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelAIAdapter extends SSEAdapter implements CustomizableStreamAdapterInterface
{
    use MapsStreamEvents;

    protected bool $started = false;

    /** @var array<string, string> */
    protected array $toolCallIds = [];

    /** @var array<string, bool> Track which tool calls emitted tool-input-start */
    protected array $toolInputStarted = [];

    public function transform(object $chunk): iterable
    {
        [$resolved, $streamEvent] = $this->resolveStreamEvent($chunk);

        if ($resolved) {
            if ($streamEvent instanceof StreamEventInterface) {
                yield from $this->handleStreamEvent($streamEvent);
            }

            return;
        }

        // Portable data may precede the message. Start lazily only when a
        // native message chunk arrives, so arbitrary objects need no messageId.
        if (! $this->started && $chunk instanceof StreamChunk) {
            $this->started = true;
            yield $this->sse(['type' => 'start', 'messageId' => $chunk->messageId]);
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

    protected function handleStreamEvent(StreamEventInterface $event): iterable
    {
        yield from match (true) {
            $event instanceof StepStartedStreamEvent => $this->handleStepEvent(
                $event->name,
                'started',
                $event->metadata,
            ),
            $event instanceof StepFinishedStreamEvent => $this->handleStepEvent(
                $event->name,
                'finished',
                $event->metadata,
            ),
            $event instanceof ActivityStreamEvent => [$this->sse([
                'type' => 'data-workflow-activity',
                'data' => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'data' => $event->data,
                ],
                'transient' => true,
            ])],
            $event instanceof CustomStreamEvent => [$this->sse([
                'type' => 'data-' . $event->name,
                'data' => $event->value,
                'transient' => true,
            ])],
            default => throw new StreamAdapterException(
                'Vercel AI cannot encode stream event ' . $event::class . '.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    protected function handleStepEvent(string $name, string $status, array $metadata): iterable
    {
        $data = [
            'name' => $name,
            'status' => $status,
        ];

        if ($metadata !== []) {
            $data['metadata'] = $metadata;
        }

        yield $this->sse([
            'type' => 'data-workflow-step',
            'data' => $data,
            'transient' => true,
        ]);
    }

    protected function handleText(TextChunk $chunk): iterable
    {
        yield $this->sse([
            'type' => 'text-delta',
            'id' => UniqueIdGenerator::generateId(),
            'messageId' => $chunk->messageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleReasoning(ReasoningChunk $chunk): iterable
    {
        yield $this->sse([
            'type' => 'reasoning-delta',
            'id' => UniqueIdGenerator::generateId(),
            'messageId' => $chunk->messageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleToolArgument(ToolArgumentChunk $chunk): iterable
    {
        $callId = $chunk->toolCallId
            ?? $this->toolCallIds[$chunk->toolName]
            ?? $this->generateId('call');
        $this->toolCallIds[$chunk->toolName] = $callId;

        if (!isset($this->toolInputStarted[$callId])) {
            $this->toolInputStarted[$callId] = true;

            yield $this->sse([
                'type' => 'tool-input-start',
                'toolCallId' => $callId,
                'toolName' => $chunk->toolName,
            ]);
        }

        yield $this->sse([
            'type' => 'tool-input-delta',
            'toolCallId' => $callId,
            'inputTextDelta' => $chunk->delta,
        ]);
    }

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        // Reuse the call id of the streamed argument deltas, if any
        $callId = $chunk->tool->getCallId()
            ?? $this->toolCallIds[$chunk->tool->getName()]
            ?? $this->generateId('call');
        $this->toolCallIds[$chunk->tool->getName()] = $callId;

        yield $this->sse([
            'type' => 'tool-input-available',
            'toolCallId' => $callId,
            'toolName' => $chunk->tool->getName(),
            'input' => $chunk->tool->getInputs(),
        ]);
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        $callId = $this->toolCallIds[$chunk->tool->getName()] ?? $this->generateId('call');

        yield $this->sse([
            'type' => 'tool-output-available',
            'toolCallId' => $callId,
            'output' => (string) $chunk->tool->getResult(),
        ]);
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ];
    }

    public function start(): iterable
    {
        return [];
    }

    public function end(): iterable
    {
        yield $this->sse(['type' => 'finish']);
        yield "data: [DONE]\n\n";
    }
}
