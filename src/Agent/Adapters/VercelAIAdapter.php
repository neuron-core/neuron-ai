<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Adapters;

use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StreamEventInterface;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\Adapter\CustomizableStreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Adapter\MapsStreamEvents;
use NeuronAI\Workflow\Streaming\Adapter\SSEAdapter;
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

    protected bool $finished = false;

    /** @var array<string, string> */
    protected array $toolCallIds = [];

    /** @var array<string, bool> Track which tool calls emitted tool-input-start */
    protected array $toolInputStarted = [];

    protected ?string $textPartId = null;
    protected ?string $reasoningPartId = null;
    protected ?string $partSourceId = null;
    protected bool $afterToolResults = false;
    protected bool $stepStarted = false;

    /** @var array<string, bool> */
    protected array $knownOutputs = [];

    /** @var array<string, bool> */
    protected array $dispatchedTools = [];

    /**
     * Reuse the last assistant message and its parts when continuing a frontend tool cycle.
     * @param list<array<string, mixed>> $parts
     */
    public function __construct(protected ?string $messageId = null, array $parts = [])
    {
        foreach ($parts as $part) {
            if (isset($part['toolCallId'])) {
                $this->toolInputStarted[$part['toolCallId']] = true;
                if (($part['state'] ?? null) === 'input-available') {
                    $this->dispatchedTools[$part['toolCallId']] = true;
                }
                if (in_array($part['state'] ?? null, ['output-available', 'output-error', 'output-denied'], true)) {
                    $this->knownOutputs[$part['toolCallId']] = true;
                }
            }
        }
    }

    protected function startMessage(?string $messageId = null): iterable
    {
        if (!$this->started) {
            $this->started = true;
            $this->messageId ??= $messageId ?? $this->generateId('msg');
            yield $this->sse(['type' => 'start', 'messageId' => $this->messageId]);
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

        // Portable data may precede the message. Start lazily only when a
        // native message chunk arrives, so arbitrary objects need no messageId.
        if ($chunk instanceof StreamChunk) {
            foreach ($this->startMessage($chunk->messageId) as $frame) {
                yield $frame;
            }
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
        foreach (match (true) {
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
        } as $frame) {
            yield $frame;
        }
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
        foreach ($this->beginInferenceStep() as $frame) {
            yield $frame;
        }
        if ($chunk->content === '') {
            return;
        }
        if ($this->reasoningPartId !== null || $this->partSourceId !== $chunk->messageId) {
            foreach ($this->closeParts() as $frame) {
                yield $frame;
            }
        }
        if ($this->textPartId === null) {
            $this->textPartId = $this->generateId('text');
            $this->partSourceId = $chunk->messageId;
            yield $this->sse(['type' => 'text-start', 'id' => $this->textPartId]);
        }
        yield $this->sse(['type' => 'text-delta', 'id' => $this->textPartId, 'delta' => $chunk->content]);
    }

    protected function handleReasoning(ReasoningChunk $chunk): iterable
    {
        foreach ($this->beginInferenceStep() as $frame) {
            yield $frame;
        }
        if ($chunk->content === '') {
            return;
        }
        if ($this->textPartId !== null || $this->partSourceId !== $chunk->messageId) {
            foreach ($this->closeParts() as $frame) {
                yield $frame;
            }
        }
        if ($this->reasoningPartId === null) {
            $this->reasoningPartId = $this->generateId('reasoning');
            $this->partSourceId = $chunk->messageId;
            yield $this->sse(['type' => 'reasoning-start', 'id' => $this->reasoningPartId]);
        }
        yield $this->sse(['type' => 'reasoning-delta', 'id' => $this->reasoningPartId, 'delta' => $chunk->content]);
    }

    protected function beginInferenceStep(): iterable
    {
        if (!$this->afterToolResults) {
            return;
        }
        $this->afterToolResults = false;
        foreach ($this->closeParts() as $frame) {
            yield $frame;
        }
        if ($this->stepStarted) {
            yield $this->sse(['type' => 'finish-step']);
        }
        $this->stepStarted = true;
        yield $this->sse(['type' => 'start-step']);
    }

    protected function closeParts(): iterable
    {
        if ($this->textPartId !== null) {
            yield $this->sse(['type' => 'text-end', 'id' => $this->textPartId]);
            $this->textPartId = null;
        }
        if ($this->reasoningPartId !== null) {
            yield $this->sse(['type' => 'reasoning-end', 'id' => $this->reasoningPartId]);
            $this->reasoningPartId = null;
        }
        $this->partSourceId = null;
    }

    protected function handleToolArgument(ToolArgumentChunk $chunk): iterable
    {
        foreach ($this->beginInferenceStep() as $frame) {
            yield $frame;
        }
        foreach ($this->publishToolArgument($chunk) as $frame) {
            yield $frame;
        }
    }

    protected function publishToolArgument(ToolArgumentChunk $chunk): iterable
    {
        foreach ($this->closeParts() as $frame) {
            yield $frame;
        }
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
        // A ToolCallChunk precedes the durable deferred handoff. Preview only;
        // input-available invokes the frontend handler immediately in useChat.
        foreach ($this->previewTool($chunk->tool) as $frame) {
            yield $frame;
        }
    }

    protected function resolveToolCallId(ToolCall $call): string
    {
        $id = $call->getCallId() ?? $this->toolCallIds[$call->getName()] ?? $this->generateId('call');
        $this->toolCallIds[$call->getName()] = $id;
        return $id;
    }

    protected function previewTool(ToolCall $call): iterable
    {
        foreach ($this->startMessage() as $frame) {
            yield $frame;
        }
        foreach ($this->closeParts() as $frame) {
            yield $frame;
        }
        $id = $this->resolveToolCallId($call);
        if (!isset($this->toolInputStarted[$id])) {
            foreach ($this->publishToolArgument(new ToolArgumentChunk(
                $this->messageId, $call->getName(), json_encode((object) $call->getInputs(), JSON_THROW_ON_ERROR), $id,
            )) as $frame) {
                yield $frame;
            }
        }
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        $this->afterToolResults = true;
        $callId = $this->resolveToolCallId($chunk->tool);
        if (isset($this->knownOutputs[$callId])) {
            return; // The frontend already owns this result, including its JSON value type.
        }
        foreach ($this->previewTool($chunk->tool) as $frame) {
            yield $frame;
        }
        $result = $chunk->tool->getResult();
        if ($chunk->tool->getApprovalState() === ApprovalState::Rejected) {
            $event = ['type' => 'tool-output-denied', 'toolCallId' => $callId];
        } elseif ($result instanceof ToolOutput && $result->isError()) {
            $event = ['type' => 'tool-output-error', 'toolCallId' => $callId, 'errorText' => $result->getText()];
        } else {
            $event = ['type' => 'tool-output-available', 'toolCallId' => $callId, 'output' => (string) $result];
        }
        yield $this->sse($event);
        $this->knownOutputs[$callId] = true;
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

    /** @param array<int, InterruptRequest> $requests */
    public function suspended(array $requests): iterable
    {
        if ($this->runFailed || $this->finished) {
            return;
        }
        foreach ($this->closeParts() as $frame) {
            yield $frame;
        }
        foreach ($requests as $request) {
            if ($request instanceof ToolResultsRequest) {
                foreach ($request->getToolCalls() as $call) {
                    $id = $this->resolveToolCallId($call);
                    if (isset($this->dispatchedTools[$id]) || isset($this->knownOutputs[$id])) {
                        continue;
                    }
                    $this->dispatchedTools[$id] = true;
                    foreach ($this->previewTool($call) as $frame) {
                        yield $frame;
                    }
                    yield $this->sse([
                        'type' => 'tool-input-available',
                        'toolCallId' => $this->resolveToolCallId($call),
                        'toolName' => $call->getName(),
                        'input' => (object) $call->getInputs(),
                    ]);
                }
            } elseif ($request instanceof ApprovalRequest) {
                foreach ($request->getActions() as $action) {
                    foreach ($this->previewTool(new ToolCall($action->name, $action->id, $action->inputs)) as $frame) {
                        yield $frame;
                    }
                    yield $this->sse([
                        'type' => 'tool-approval-request',
                        'toolCallId' => $action->id,
                        'approvalId' => $action->id,
                        'reason' => $action->reason ?? $request->getMessage(),
                    ]);
                    if (!$action->isPending()) {
                        yield $this->sse([
                            'type' => 'tool-approval-response',
                            'approvalId' => $action->id,
                            'approved' => $action->isApproved(),
                            ...($action->feedback === null ? [] : ['reason' => $action->feedback]),
                        ]);
                    }
                }
            } else {
                yield $this->sse([
                    'type' => 'data-workflow-interrupt',
                    'data' => $request->jsonSerialize(),
                    'transient' => true,
                ]);
            }
        }
        foreach ($this->end() as $frame) {
            yield $frame;
        }
    }

    public function error(Throwable $error): iterable
    {
        if ($this->runFailed || $this->finished) {
            return;
        }

        $this->runFailed = true;
        foreach ($this->closeParts() as $frame) {
            yield $frame;
        }

        yield $this->sse([
            'type' => 'error',
            'errorText' => $error->getMessage(),
        ]);
        yield "data: [DONE]\n\n";
    }

    public function end(): iterable
    {
        if ($this->runFailed || $this->finished) {
            return;
        }
        $this->finished = true;

        foreach ($this->closeParts() as $frame) {
            yield $frame;
        }
        if ($this->stepStarted) {
            yield $this->sse(['type' => 'finish-step']);
        }
        yield $this->sse(['type' => 'finish']);
        yield "data: [DONE]\n\n";
    }
}
