<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use DateTimeImmutable;
use DateTimeInterface;
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
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use Throwable;

use function array_keys;
use function json_encode;

/**
 * Adapter for the AG-UI streaming protocol (agent-frontend interaction).
 *
 * @see https://docs.ag-ui.com/concepts/events
 * @see https://docs.ag-ui.com/concepts/interrupts
 */
class AGUIAdapter extends SSEAdapter implements CustomizableStreamAdapterInterface
{
    use MapsStreamEvents;

    protected ?string $currentMessageId = null;

    protected bool $messageStarted = false;

    protected bool $runFailed = false;

    /** @var array<string, string> Tool name to tool call ID */
    protected array $toolCallIds = [];

    /** @var array<string, bool> */
    protected array $toolCallStarted = [];

    /** @var array<string, bool> Started tool calls whose TOOL_CALL_END was not emitted yet */
    protected array $openToolCalls = [];

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

        foreach ($this->startToolCall($toolCallId, $chunk->toolName, $parentMessageId) as $event) {
            yield $event;
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

        foreach ($this->startToolCall($toolCallId, $toolName, $parentMessageId) as $event) {
            yield $event;
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

        foreach ($this->endToolCall($toolCallId) as $event) {
            yield $event;
        }
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

    /**
     * @return iterable<string>
     */
    protected function startToolCall(string $toolCallId, string $toolName, ?string $parentMessageId): iterable
    {
        if (isset($this->toolCallStarted[$toolCallId])) {
            return;
        }

        $this->toolCallStarted[$toolCallId] = true;
        $this->openToolCalls[$toolCallId] = true;

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

    /**
     * @return iterable<string>
     */
    protected function endToolCall(string $toolCallId): iterable
    {
        if (! isset($this->openToolCalls[$toolCallId])) {
            return;
        }

        unset($this->openToolCalls[$toolCallId]);

        yield $this->sse([
            'type' => 'TOOL_CALL_END',
            'toolCallId' => $toolCallId,
        ]);
    }

    public function start(): iterable
    {
        if ($this->runFailed) {
            return;
        }

        $this->runId ??= $this->generateId('run');

        yield $this->sse([
            'type' => 'RUN_STARTED',
            'runId' => $this->runId,
            'threadId' => $this->threadId,
        ]);
    }

    /**
     * Terminate a suspended run instead of calling end(): the protocol forbids
     * finishing a run with an active tool call, so every open call is closed,
     * calls awaiting a decision that never reached the stream are announced,
     * and RUN_FINISHED carries the interrupt outcome the client resumes from.
     *
     * @param array<int, InterruptRequest> $requests
     * @return iterable<string>
     */
    public function suspended(array $requests): iterable
    {
        if ($this->runFailed) {
            return;
        }

        // Capture the parent message id before closing the text stream resets it
        $parentMessageId = $this->currentMessageId;

        foreach ($this->endReasoning() as $event) {
            yield $event;
        }
        foreach ($this->endText() as $event) {
            yield $event;
        }

        $interrupts = [];
        foreach ($requests as $request) {
            if (! $request instanceof ApprovalRequest) {
                $interrupts[] = $this->interrupt($request);
                continue;
            }

            foreach ($request->getActions() as $action) {
                foreach ($this->announceToolCall($action, $parentMessageId) as $event) {
                    yield $event;
                }

                $interrupts[] = $this->toolCallInterrupt($request, $action);
            }
        }

        foreach (array_keys($this->openToolCalls) as $toolCallId) {
            foreach ($this->endToolCall($toolCallId) as $event) {
                yield $event;
            }
        }

        if ($this->runId === null) {
            return;
        }

        yield $this->sse([
            'type' => 'RUN_FINISHED',
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            'outcome' => ['type' => 'interrupt', 'interrupts' => $interrupts],
        ]);
    }

    /**
     * A gated call that never reached the stream (a buffered turn, or a
     * provider sending its arguments in one shot) is announced here, so the
     * interrupt binds to a tool call the client knows.
     *
     * @return iterable<string>
     */
    protected function announceToolCall(Action $action, ?string $parentMessageId): iterable
    {
        if (isset($this->toolCallStarted[$action->id])) {
            return;
        }

        foreach ($this->startToolCall($action->id, $action->name, $parentMessageId) as $event) {
            yield $event;
        }

        if ($action->inputs !== []) {
            yield $this->sse([
                'type' => 'TOOL_CALL_ARGS',
                'toolCallId' => $action->id,
                'delta' => json_encode($action->inputs),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function toolCallInterrupt(ApprovalRequest $request, Action $action): array
    {
        return $this->withExpiry([
            'id' => $action->id,
            'reason' => 'tool_call',
            'toolCallId' => $action->id,
            'message' => $action->reason ?? $request->getMessage(),
            'metadata' => $action->jsonSerialize(),
        ], $request);
    }

    /**
     * A request with no protocol-native shape keeps its portable description
     * as metadata under a Neuron-namespaced reason.
     *
     * @return array<string, mixed>
     */
    protected function interrupt(InterruptRequest $request): array
    {
        return $this->withExpiry([
            'id' => (string) $request->getId(),
            'reason' => 'neuron:' . $request->type()->value,
            'message' => $request->getMessage(),
            'metadata' => $request->jsonSerialize(),
        ], $request);
    }

    /**
     * @param array<string, mixed> $interrupt
     * @return array<string, mixed>
     */
    protected function withExpiry(array $interrupt, InterruptRequest $request): array
    {
        $expiresAt = $request instanceof WaitForEventRequest ? $request->getExpiresAt() : null;

        if ($expiresAt instanceof DateTimeImmutable) {
            $interrupt['expiresAt'] = $expiresAt->format(DateTimeInterface::ATOM);
        }

        return $interrupt;
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

    /**
     * Terminate a failed run instead of calling end().
     *
     * @return iterable<string>
     */
    public function error(Throwable $error): iterable
    {
        if ($this->runFailed) {
            return;
        }

        $this->runFailed = true;

        yield from $this->endReasoning();
        yield from $this->endText();

        $event = [
            'type' => 'RUN_ERROR',
            'message' => $error->getMessage(),
        ];

        if ($error->getCode() !== 0) {
            $event['code'] = (string) $error->getCode();
        }

        yield $this->sse($event);
    }

    public function end(): iterable
    {
        if ($this->runFailed) {
            return;
        }

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
