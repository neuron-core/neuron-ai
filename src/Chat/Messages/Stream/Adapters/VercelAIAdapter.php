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
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Throwable;

/**
 * Adapter for Vercel AI SDK Data Stream Protocol.
 *
 * @see https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelAIAdapter extends SSEAdapter implements CustomizableStreamAdapterInterface
{
    use MapsStreamEvents;

    protected bool $started = false;

    protected bool $runFailed = false;

    /** @var array<string, string> */
    protected array $toolCallIds = [];

    /** @var array<string, bool> Track which tool calls emitted tool-input-start */
    protected array $toolInputStarted = [];

    /**
     * @throws StreamAdapterException
     */
    public function transform(object $chunk): iterable
    {
        if ($this->runFailed) {
            return;
        }

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

    /**
     * @throws StreamAdapterException
     */
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

    /**
     * Terminate a suspended run instead of calling end(). A pending approval
     * is the protocol's own tool-approval request: the call's complete input
     * lands first (only deltas, or nothing, reached the stream before the
     * gate), then the request per action. Any other interrupt travels as a
     * transient data part.
     *
     * @param array<int, InterruptRequest> $requests
     * @return iterable<string>
     */
    public function suspended(array $requests): iterable
    {
        if ($this->runFailed) {
            return;
        }

        foreach ($requests as $request) {
            if (! $request instanceof ApprovalRequest) {
                yield $this->sse([
                    'type' => 'data-workflow-interrupt',
                    'data' => $request->jsonSerialize(),
                    'transient' => true,
                ]);
                continue;
            }

            // A buffered turn streamed no message chunk: the tool parts still need one.
            if (! $this->started) {
                $this->started = true;
                yield $this->sse(['type' => 'start', 'messageId' => $this->generateId('msg')]);
            }

            foreach ($request->getActions() as $action) {
                yield $this->sse([
                    'type' => 'tool-input-available',
                    'toolCallId' => $action->id,
                    'toolName' => $action->name,
                    'input' => $action->inputs,
                ]);

                yield $this->sse([
                    'type' => 'tool-approval-request',
                    'toolCallId' => $action->id,
                    'approvalId' => $action->id,
                    'reason' => $action->reason ?? $request->getMessage(),
                ]);
            }
        }

        yield $this->sse(['type' => 'finish']);
        yield "data: [DONE]\n\n";
    }

    public function error(Throwable $error): iterable
    {
        if ($this->runFailed) {
            return;
        }

        $this->runFailed = true;

        yield $this->sse([
            'type' => 'error',
            'errorText' => $error->getMessage(),
        ]);
        yield "data: [DONE]\n\n";
    }

    public function end(): iterable
    {
        if ($this->runFailed) {
            return;
        }

        yield $this->sse(['type' => 'finish']);
        yield "data: [DONE]\n\n";
    }
}
