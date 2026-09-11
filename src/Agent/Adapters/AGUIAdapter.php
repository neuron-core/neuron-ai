<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Adapters;

use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StreamEventInterface;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Streaming\Adapter\CustomizableStreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Adapter\MapsStreamEvents;
use NeuronAI\Workflow\Streaming\Adapter\SSEAdapter;
use Throwable;
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

    protected bool $finished = false;

    /** @var array<string, string> Tool name to tool call ID */
    protected array $toolCallIds = [];

    /** @var array<string, bool> */
    protected array $toolCallStarted = [];

    /** @var array<string, bool> Started tool calls whose TOOL_CALL_END was not emitted yet */
    protected array $openToolCalls = [];

    /** @var array<string, list<string>> */
    protected array $argumentDeltas = [];

    /** @var array<string, array<string, mixed>> */
    protected array $messages = [];

    /** @var array<string, bool> */
    protected array $knownResults = [];

    protected bool $reasoningStarted = false;

    protected ?string $reasoningMessageId = null;

    /**
     * Seed the protocol snapshot with the frontend's current conversation and state.
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $state
     */
    public function __construct(
        protected string $threadId,
        protected ?string $runId = null,
        array $messages = [],
        protected array $state = [],
    ) {
        foreach ($messages as $message) {
            $this->messages[$message['id']] = $message;
            foreach ($message['toolCalls'] ?? [] as $call) {
                $this->toolCallStarted[$call['id']] = true;
            }
            if (($message['role'] ?? null) === 'tool') {
                $this->knownResults[$message['toolCallId']] = true;
            }
        }
    }

    /**
     * @throws StreamAdapterException
     */
    public function transform(object $chunk): iterable
    {
        if ($this->runFailed || $this->finished) {
            return;
        }

        [$resolved, $streamEvent] = $this->resolveStreamEvent($chunk);

        if ($resolved) {
            if ($streamEvent instanceof StreamEventInterface) {
                foreach ($this->handleStreamEvent($streamEvent) as $frame) {
                    yield $frame;
                }
            }

            return;
        }

        foreach (match (true) {
            $chunk instanceof TextChunk => $this->handleText($chunk),
            $chunk instanceof ReasoningChunk => $this->handleReasoning($chunk),
            $chunk instanceof ToolArgumentChunk => $this->handleToolArgument($chunk),
            $chunk instanceof ToolCallChunk => $this->handleToolCall($chunk),
            $chunk instanceof ToolResultChunk => $this->handleToolResult($chunk),
            default => []
        } as $frame) {
            yield $frame;
        }
    }

    /**
     * @throws StreamAdapterException
     */
    protected function handleStreamEvent(StreamEventInterface $event): iterable
    {
        if ($event instanceof ActivityStreamEvent) {
            $this->messages[$event->id] = [
                'id' => $event->id, 'role' => 'activity', 'activityType' => $event->type, 'content' => (object) $event->data,
            ];
        }
        foreach (match (true) {
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
        } as $frame) {
            yield $frame;
        }
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

        if ($this->messageStarted && $chunk->messageId !== null && $this->currentMessageId !== $chunk->messageId) {
            foreach ($this->endText() as $frame) {
                yield $frame;
            }
        }

        if (! $this->messageStarted) {
            $this->currentMessageId = $chunk->messageId ?? $this->generateId('msg');
            $this->messageStarted = true;

            yield $this->sse([
                'type' => 'TEXT_MESSAGE_START',
                'messageId' => $this->currentMessageId,
                'role' => 'assistant',
            ]);
        }

        $id = $this->currentMessageId;
        $this->messages[$id] ??= ['id' => $id, 'role' => 'assistant', 'content' => ''];
        $this->messages[$id]['content'] .= $chunk->content;

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

        $reasoningId = 'reasoning_' . $chunk->messageId;
        if ($this->reasoningStarted && $this->reasoningMessageId !== $reasoningId) {
            foreach ($this->endReasoning() as $frame) {
                yield $frame;
            }
        }
        if (! $this->reasoningStarted) {
            $this->reasoningStarted = true;
            $this->reasoningMessageId = $reasoningId;

            yield $this->sse([
                'type' => 'REASONING_START',
                'messageId' => $this->reasoningMessageId,
            ]);

            yield $this->sse([
                'type' => 'REASONING_MESSAGE_START',
                'messageId' => $this->reasoningMessageId,
                'role' => 'reasoning',
            ]);
        }

        $this->messages[$reasoningId] ??= ['id' => $reasoningId, 'role' => 'reasoning', 'content' => ''];
        $this->messages[$reasoningId]['content'] .= $chunk->content;

        yield $this->sse([
            'type' => 'REASONING_MESSAGE_CONTENT',
            'messageId' => $this->reasoningMessageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleToolArgument(ToolArgumentChunk $chunk): iterable
    {
        // AG-UI clients may execute unanswered calls when the run finishes.
        // Keep proposals off the executable tool channel until dispatch commits.
        $id = $chunk->toolCallId ?? $this->toolCallIds[$chunk->toolName] ?? $this->generateId('call');
        $this->toolCallIds[$chunk->toolName] = $id;
        $this->argumentDeltas[$id][] = $chunk->delta;
        return [];
    }

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        $this->resolveToolCallId($chunk);
        return [];
    }

    protected function publishToolCall(ToolCall $call): iterable
    {
        $toolCallId = $this->resolveToolCallId(new ToolCallChunk($call));
        if (isset($this->toolCallStarted[$toolCallId])) {
            return;
        }
        $parentMessageId = $this->currentMessageId ?? $this->generateId('msg');
        foreach ($this->endReasoning() as $frame) {
            yield $frame;
        }
        foreach ($this->endText() as $frame) {
            yield $frame;
        }
        foreach ($this->startToolCall($toolCallId, $call->getName(), $parentMessageId) as $frame) {
            yield $frame;
        }
        $arguments = json_encode((object) $call->getInputs(), JSON_THROW_ON_ERROR);
        foreach ($this->argumentDeltas[$toolCallId] ?? [$arguments] as $delta) {
            yield $this->sse(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $toolCallId, 'delta' => $delta]);
        }
        unset($this->argumentDeltas[$toolCallId]);
        foreach ($this->endToolCall($toolCallId) as $frame) {
            yield $frame;
        }
        $this->messages[$parentMessageId] ??= ['id' => $parentMessageId, 'role' => 'assistant', 'content' => ''];
        $this->messages[$parentMessageId]['toolCalls'][] = [
            'id' => $toolCallId,
            'type' => 'function',
            'function' => ['name' => $call->getName(), 'arguments' => $arguments],
        ];
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        $toolCallId = $this->resolveToolCallId($chunk);
        if (isset($this->knownResults[$toolCallId])) {
            return;
        }
        foreach ($this->publishToolCall($chunk->tool) as $frame) {
            yield $frame;
        }
        $result = $chunk->tool->getResult();
        $id = $this->generateId('msg');
        $message = [
            'id' => $id, 'role' => 'tool', 'toolCallId' => $toolCallId, 'content' => (string) $result,
        ];
        if ($result instanceof ToolOutput && $result->isError()) {
            $message['error'] = $result->getText();
        }
        $this->messages[$id] = $message;
        $this->knownResults[$toolCallId] = true;
        unset($message['id']);
        yield $this->sse(['type' => 'TOOL_CALL_RESULT', 'messageId' => $id, ...$message]);
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
        if ($this->runFailed || $this->finished) {
            return;
        }

        $this->runId ??= $this->generateId('run');

        yield $this->sse([
            'type' => 'RUN_STARTED',
            'runId' => $this->runId,
            'threadId' => $this->threadId,
        ]);
    }

    /** @param array<int, InterruptRequest> $requests */
    public function suspended(array $requests): iterable
    {
        if ($this->runFailed || $this->finished) {
            return;
        }
        foreach ($this->endReasoning() as $frame) {
            yield $frame;
        }
        foreach ($this->endText() as $frame) {
            yield $frame;
        }
        $interrupts = [];
        $frontendHandoff = array_filter($requests, fn (InterruptRequest $request): bool => !$request instanceof ToolResultsRequest) === [];
        foreach ($requests as $request) {
            if ($request instanceof ToolResultsRequest && $frontendHandoff) {
                foreach ($request->getToolCalls() as $call) {
                    foreach ($this->publishToolCall($call) as $frame) {
                        yield $frame;
                    }
                }
            } elseif ($request instanceof ApprovalRequest) {
                foreach ($request->getActions() as $action) {
                    $interrupts[] = $this->withExpiry([
                        'id' => $action->id,
                        'reason' => 'confirmation',
                        'message' => $action->reason ?? $request->getMessage(),
                        'responseSchema' => [
                            'type' => 'object',
                            'properties' => ['approved' => ['type' => 'boolean'], 'reason' => ['type' => 'string']],
                            'required' => ['approved'],
                        ],
                        'metadata' => $action->jsonSerialize(),
                    ], $request);
                }
            } else {
                $interrupts[] = $this->interrupt($request);
            }
        }
        if ($interrupts === []) {
            // Ordinary frontend tools return role:tool messages, not resume[].
            foreach ($this->end() as $frame) {
                yield $frame;
            }
            return;
        }
        $this->finished = true;
        yield $this->sse(['type' => 'STATE_SNAPSHOT', 'snapshot' => (object) $this->state]);
        yield $this->sse(['type' => 'MESSAGES_SNAPSHOT', 'messages' => array_values($this->messages)]);
        yield $this->sse([
            'type' => 'RUN_FINISHED',
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            'outcome' => ['type' => 'interrupt', 'interrupts' => $interrupts],
        ]);
    }

    /** @return array<string, mixed> */
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
        if ($this->runFailed || $this->finished) {
            return;
        }

        $this->runFailed = true;

        foreach ($this->endReasoning() as $frame) {
            yield $frame;
        }
        foreach ($this->endText() as $frame) {
            yield $frame;
        }

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
        if ($this->runFailed || $this->finished) {
            return;
        }
        $this->finished = true;

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
