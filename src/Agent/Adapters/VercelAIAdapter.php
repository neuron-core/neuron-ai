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
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\Adapter\CustomizableStreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Adapter\MapsStreamEvents;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

use function in_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Adapter for Vercel AI SDK Data Stream Protocol.
 *
 * @see https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelAIAdapter implements CustomizableStreamAdapterInterface
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
            $this->messageId ??= $messageId ?? UniqueIdGenerator::generateId('msg_');
            yield new ProtocolEvent('start', ['messageId' => $this->messageId]);
        }
    }

    /**
     * Kept across segments: the message being continued and the tool parts
     * the frontend already holds, so a continuation neither re-emits nor
     * forgets them.
     */
    public function reset(): void
    {
        $this->started = false;
        $this->runFailed = false;
        $this->finished = false;
        $this->toolCallIds = [];
        $this->textPartId = null;
        $this->reasoningPartId = null;
        $this->partSourceId = null;
        $this->afterToolResults = false;
        $this->stepStarted = false;
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
            $event instanceof ActivityStreamEvent => [new ProtocolEvent('data-workflow-activity', [
                'data' => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'data' => $event->data,
                ],
                'transient' => true,
            ])],
            $event instanceof CustomStreamEvent => [new ProtocolEvent('data-' . $event->name, [
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

        yield new ProtocolEvent('data-workflow-step', [
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
            $this->textPartId = UniqueIdGenerator::generateId('text_');
            $this->partSourceId = $chunk->messageId;
            yield new ProtocolEvent('text-start', ['id' => $this->textPartId]);
        }
        yield new ProtocolEvent('text-delta', ['id' => $this->textPartId, 'delta' => $chunk->content]);
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
            $this->reasoningPartId = UniqueIdGenerator::generateId('reasoning_');
            $this->partSourceId = $chunk->messageId;
            yield new ProtocolEvent('reasoning-start', ['id' => $this->reasoningPartId]);
        }
        yield new ProtocolEvent('reasoning-delta', ['id' => $this->reasoningPartId, 'delta' => $chunk->content]);
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
            yield new ProtocolEvent('finish-step');
        }
        $this->stepStarted = true;
        yield new ProtocolEvent('start-step');
    }

    protected function closeParts(): iterable
    {
        if ($this->textPartId !== null) {
            yield new ProtocolEvent('text-end', ['id' => $this->textPartId]);
            $this->textPartId = null;
        }
        if ($this->reasoningPartId !== null) {
            yield new ProtocolEvent('reasoning-end', ['id' => $this->reasoningPartId]);
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
            ?? UniqueIdGenerator::generateId('call_');
        $this->toolCallIds[$chunk->toolName] = $callId;

        if (!isset($this->toolInputStarted[$callId])) {
            $this->toolInputStarted[$callId] = true;

            yield new ProtocolEvent('tool-input-start', [
                'toolCallId' => $callId,
                'toolName' => $chunk->toolName,
            ]);
        }

        yield new ProtocolEvent('tool-input-delta', [
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
        $id = $call->getCallId() ?? $this->toolCallIds[$call->getName()] ?? UniqueIdGenerator::generateId('call_');
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
                $this->messageId,
                $call->getName(),
                json_encode((object) $call->getInputs(), JSON_THROW_ON_ERROR),
                $id,
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
            $event = new ProtocolEvent('tool-output-denied', ['toolCallId' => $callId]);
        } elseif ($result instanceof ToolOutput && $result->isError()) {
            $event = new ProtocolEvent('tool-output-error', ['toolCallId' => $callId, 'errorText' => $result->getText()]);
        } else {
            $event = new ProtocolEvent('tool-output-available', ['toolCallId' => $callId, 'output' => (string) $result]);
        }
        yield $event;
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

    public function interrupt(InterruptRequest $request): iterable
    {
        if ($this->runFailed || $this->finished) {
            return;
        }
        foreach ($this->closeParts() as $frame) {
            yield $frame;
        }
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
                yield new ProtocolEvent('tool-input-available', [
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
                yield new ProtocolEvent('tool-approval-request', [
                    'toolCallId' => $action->id,
                    'approvalId' => $action->id,
                    'reason' => $action->reason ?? $request->getMessage(),
                ]);
                if (!$action->isPending()) {
                    yield new ProtocolEvent('tool-approval-response', [
                        'approvalId' => $action->id,
                        'approved' => $action->isApproved(),
                        ...($action->feedback === null ? [] : ['reason' => $action->feedback]),
                    ]);
                }
            }
        } else {
            yield new ProtocolEvent('data-workflow-interrupt', [
                'data' => $request->jsonSerialize(),
                'transient' => true,
            ]);
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

        yield new ProtocolEvent('error', [
            'errorText' => $this->errorMessage($error),
        ]);
    }

    /**
     * The failure text a client may see. Exception messages carry internals
     * (provider URLs and response bodies, file paths, run identifiers), so
     * the wire gets a neutral text; override to expose what your clients may
     * know.
     */
    protected function errorMessage(Throwable $error): string
    {
        return 'The run failed.';
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
            yield new ProtocolEvent('finish-step');
        }
        yield new ProtocolEvent('finish');
    }
}
