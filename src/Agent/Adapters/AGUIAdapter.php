<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Adapters;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StreamEventInterface;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_map;
use function implode;
use function in_array;
use function is_string;
use function json_encode;
use function array_values;

use const JSON_THROW_ON_ERROR;

/**
 * Adapter for the AG-UI streaming protocol (agent-frontend interaction).
 *
 * @see https://docs.ag-ui.com/concepts/events
 * @see https://docs.ag-ui.com/concepts/interrupts
 */
class AGUIAdapter implements CustomizableStreamAdapterInterface
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

    /** @var array<string, string> Tool call ID to the ID of the message holding the call */
    protected array $parentMessageIds = [];

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
     * Kept across segments: the thread and run identity, the seeded snapshot,
     * and the calls and results already published, so a continuation neither
     * re-emits nor forgets them.
     */
    public function reset(): void
    {
        $this->currentMessageId = null;
        $this->messageStarted = false;
        $this->runFailed = false;
        $this->finished = false;
        $this->toolCallIds = [];
        $this->parentMessageIds = [];
        $this->openToolCalls = [];
        $this->argumentDeltas = [];
        $this->reasoningStarted = false;
        $this->reasoningMessageId = null;
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
            $event instanceof ActivityStreamEvent => [new ProtocolEvent('ACTIVITY_SNAPSHOT', [
                'messageId' => $event->id,
                'activityType' => $event->type,
                'content' => (object) $event->data,
                'replace' => true,
            ])],
            $event instanceof CustomStreamEvent => [new ProtocolEvent('CUSTOM', [
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
        $data = ['stepName' => $name];

        if ($metadata !== []) {
            $data['metadata'] = $metadata;
        }

        yield new ProtocolEvent($type, $data);
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
            $this->currentMessageId = $chunk->messageId ?? UniqueIdGenerator::generateId('msg_');
            $this->messageStarted = true;

            yield new ProtocolEvent('TEXT_MESSAGE_START', [
                'messageId' => $this->currentMessageId,
                'role' => 'assistant',
            ]);
        }

        $id = $this->currentMessageId;
        $this->messages[$id] ??= ['id' => $id, 'role' => 'assistant', 'content' => ''];
        $this->messages[$id]['content'] .= $chunk->content;

        yield new ProtocolEvent('TEXT_MESSAGE_CONTENT', [
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

            yield new ProtocolEvent('REASONING_START', [
                'messageId' => $this->reasoningMessageId,
            ]);

            yield new ProtocolEvent('REASONING_MESSAGE_START', [
                'messageId' => $this->reasoningMessageId,
                'role' => 'reasoning',
            ]);
        }

        $this->messages[$reasoningId] ??= ['id' => $reasoningId, 'role' => 'reasoning', 'content' => ''];
        $this->messages[$reasoningId]['content'] .= $chunk->content;

        yield new ProtocolEvent('REASONING_MESSAGE_CONTENT', [
            'messageId' => $this->reasoningMessageId,
            'delta' => $chunk->content,
        ]);
    }

    protected function handleToolArgument(ToolArgumentChunk $chunk): iterable
    {
        // AG-UI clients may execute unanswered calls when the run finishes.
        // Keep proposals off the executable tool channel until dispatch commits.
        $id = $chunk->toolCallId ?? $this->toolCallIds[$chunk->toolName] ?? UniqueIdGenerator::generateId('call_');
        $this->toolCallIds[$chunk->toolName] = $id;
        $this->argumentDeltas[$id][] = $chunk->delta;
        return [];
    }

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        $this->parentMessageIds[$this->resolveToolCallId($chunk->tool)] = $chunk->messageId;
        return [];
    }

    protected function publishToolCall(ToolCall $call): iterable
    {
        $toolCallId = $this->resolveToolCallId($call);
        if (isset($this->toolCallStarted[$toolCallId])) {
            return;
        }
        $parentMessageId = $this->parentMessageIds[$toolCallId] ?? $this->currentMessageId ?? UniqueIdGenerator::generateId('msg_');
        foreach ($this->endReasoning() as $frame) {
            yield $frame;
        }
        foreach ($this->endText() as $frame) {
            yield $frame;
        }
        foreach ($this->startToolCall($toolCallId, $call->getName(), $parentMessageId) as $frame) {
            yield $frame;
        }
        $toolCall = $this->toolCall($toolCallId, $call);
        foreach ($this->argumentDeltas[$toolCallId] ?? [$toolCall['function']['arguments']] as $delta) {
            yield new ProtocolEvent('TOOL_CALL_ARGS', ['toolCallId' => $toolCallId, 'delta' => $delta]);
        }
        unset($this->argumentDeltas[$toolCallId]);
        foreach ($this->endToolCall($toolCallId) as $frame) {
            yield $frame;
        }
        $this->messages[$parentMessageId] ??= ['id' => $parentMessageId, 'role' => 'assistant', 'content' => ''];
        $this->messages[$parentMessageId]['toolCalls'][] = $toolCall;
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        $toolCallId = $this->resolveToolCallId($chunk->tool);
        if (isset($this->knownResults[$toolCallId])) {
            return;
        }
        foreach ($this->publishToolCall($chunk->tool) as $frame) {
            yield $frame;
        }
        $message = $this->toolResult($toolCallId, $chunk->tool->getResult());
        $id = $message['id'];
        $this->messages[$id] = $message;
        $this->knownResults[$toolCallId] = true;
        unset($message['id']);
        yield new ProtocolEvent('TOOL_CALL_RESULT', ['messageId' => $id, ...$message]);
    }

    /**
     * @return array{id: string, type: string, function: array{name: string, arguments: string}}
     * @throws JsonException
     */
    protected function toolCall(string $toolCallId, ToolCall $call): array
    {
        return [
            'id' => $toolCallId,
            'type' => 'function',
            'function' => ['name' => $call->getName(), 'arguments' => json_encode((object) $call->getInputs(), JSON_THROW_ON_ERROR)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function toolResult(string $toolCallId, string|ToolOutput|null $result): array
    {
        // Derived from the call, so a reload rebuilds the same ID from the stored result.
        $message = ['id' => 'result_' . $toolCallId, 'role' => 'tool', 'toolCallId' => $toolCallId, 'content' => (string) $result];
        if ($result instanceof ToolOutput && $result->isError()) {
            $message['error'] = $result->getText();
        }

        return $message;
    }

    protected function resolveToolCallId(ToolCall $call): string
    {
        $toolName = $call->getName();
        $toolCallId = $call->getCallId()
            ?? $this->toolCallIds[$toolName]
            ?? UniqueIdGenerator::generateId('call_');
        $this->toolCallIds[$toolName] = $toolCallId;

        return $toolCallId;
    }

    /**
     * @return iterable<ProtocolEvent>
     */
    protected function startToolCall(string $toolCallId, string $toolName, ?string $parentMessageId): iterable
    {
        if (isset($this->toolCallStarted[$toolCallId])) {
            return;
        }

        $this->toolCallStarted[$toolCallId] = true;
        $this->openToolCalls[$toolCallId] = true;

        $data = [
            'toolCallId' => $toolCallId,
            'toolCallName' => $toolName,
        ];

        if ($parentMessageId !== null) {
            $data['parentMessageId'] = $parentMessageId;
        }

        yield new ProtocolEvent('TOOL_CALL_START', $data);
    }

    /**
     * @return iterable<ProtocolEvent>
     */
    protected function endToolCall(string $toolCallId): iterable
    {
        if (! isset($this->openToolCalls[$toolCallId])) {
            return;
        }

        unset($this->openToolCalls[$toolCallId]);

        yield new ProtocolEvent('TOOL_CALL_END', [
            'toolCallId' => $toolCallId,
        ]);
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
        if ($this->runFailed || $this->finished) {
            return;
        }

        $this->runId ??= UniqueIdGenerator::generateId('run_');

        yield new ProtocolEvent('RUN_STARTED', [
            'runId' => $this->runId,
            'threadId' => $this->threadId,
        ]);
    }

    /**
     * @throws WorkflowException
     * @throws JsonException
     */
    public function interrupt(InterruptRequest $request): iterable
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
        if ($request instanceof ToolResultsRequest) {
            foreach ($request->getToolCalls() as $call) {
                foreach ($this->publishToolCall($call) as $frame) {
                    yield $frame;
                }
            }
        } elseif ($request instanceof ApprovalRequest) {
            $interrupts = $this->confirmations($request);
        } else {
            $interrupts[] = $this->interruption($request);
        }
        if ($interrupts === []) {
            // Ordinary frontend tools return role:tool messages, not resume[].
            foreach ($this->end() as $frame) {
                yield $frame;
            }
            return;
        }
        $this->finished = true;
        yield new ProtocolEvent('STATE_SNAPSHOT', ['snapshot' => (object) $this->state]);
        yield new ProtocolEvent('MESSAGES_SNAPSHOT', ['messages' => array_values($this->messages)]);
        yield new ProtocolEvent('RUN_FINISHED', [
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            'outcome' => ['type' => 'interrupt', 'interrupts' => $interrupts],
        ]);
    }

    /**
     * What a client held when the live stream ended, rebuilt from storage for a page
     * reload: `messages` seed the client's initial messages, `interrupts` its pending
     * interrupts. Pass the run's snapshot only with the latest page; older pages take null.
     *
     * @param Message[] $messages Stored messages in insertion order.
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>}
     * @throws JsonException
     * @throws WorkflowException
     */
    public function hydrate(array $messages, ?WorkflowRunSnapshot $run): array
    {
        $messages = [...$messages, ...$this->uncommittedInput($messages, $run)];
        $waiting = $run?->status === WorkflowStatus::Suspended ? $run->interrupt : null;

        // Frontend calls a suspended run waits on: null while pending, else the accepted result.
        $dispatched = [];
        if ($waiting instanceof ToolResultsRequest) {
            foreach ($waiting->getToolCalls() as $call) {
                $dispatched[$call->getCallId()] = null;
            }
            foreach ($waiting->getResults() as $callId => $result) {
                $dispatched[$callId] = $this->acceptedResult($result);
            }
        }
        $answered = [];
        foreach ($messages as $message) {
            foreach ($message instanceof ToolResultMessage ? $message->getToolCalls() : [] as $call) {
                $answered[$call->getCallId()] = true;
            }
        }

        $hydrated = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $call) {
                    $hydrated[] = $this->toolResult((string) $call->getCallId(), $call->getResult());
                }
            } elseif ($message instanceof AssistantMessage) {
                // On the latest page only the batch in progress lacks results. Like the live
                // stream, publish its calls once dispatched to the frontend, never before.
                $calls = array_filter(
                    $message instanceof ToolCallMessage ? $message->getToolCalls() : [],
                    static fn (ToolCall $call): bool => !$run instanceof WorkflowRunSnapshot
                        || isset($answered[$call->getCallId()])
                        || array_key_exists((string) $call->getCallId(), $dispatched),
                );
                $hydrated = [...$hydrated, ...$this->assistant($message, $calls)];
                foreach ($calls as $call) {
                    if (isset($dispatched[$call->getCallId()])) {
                        $hydrated[] = $this->toolResult((string) $call->getCallId(), $dispatched[$call->getCallId()]);
                    }
                }
            } else {
                $hydrated[] = ['id' => $message->getId(), 'role' => $message->getRole(), 'content' => $this->content($message)];
            }
        }

        return [
            'messages' => $hydrated,
            'interrupts' => match (true) {
                $waiting === null => [],
                $waiting instanceof ApprovalRequest => $this->confirmations($waiting),
                default => [$this->interruption($waiting)],
            },
        ];
    }

    /**
     * The run's input not yet in history: a user message is written only after the
     * run's first inference succeeds, and keeps its ID when it is.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function uncommittedInput(array $messages, ?WorkflowRunSnapshot $run): array
    {
        if (!$run instanceof WorkflowRunSnapshot || $run->status === WorkflowStatus::Completed || !$run->startEvent instanceof AgentStartEvent) {
            return [];
        }
        $stored = array_map(static fn (Message $message): string => $message->getId(), $messages);

        return array_filter($run->startEvent->messages, static fn (Message $message): bool => !in_array($message->getId(), $stored, true));
    }

    /**
     * @param ToolCall[] $calls The calls the client may see.
     * @return list<array<string, mixed>>
     * @throws JsonException
     */
    protected function assistant(AssistantMessage $message, array $calls): array
    {
        $hydrated = [];
        $reasoning = implode('', array_map(
            static fn (ContentBlockInterface $block): string => $block->getContent(),
            array_filter($message->getContentBlocks(), static fn (ContentBlockInterface $block): bool => $block instanceof ReasoningContent),
        ));
        if ($reasoning !== '') {
            $hydrated[] = ['id' => 'reasoning_' . $message->getId(), 'role' => 'reasoning', 'content' => $reasoning];
        }
        if ($message->getContent() !== null || $calls !== []) {
            $assistant = ['id' => $message->getId(), 'role' => 'assistant', 'content' => $message->getContent() ?? ''];
            if ($calls !== []) {
                $assistant['toolCalls'] = array_values(array_map(
                    fn (ToolCall $call): array => $this->toolCall((string) $call->getCallId(), $call),
                    $calls,
                ));
            }
            $hydrated[] = $assistant;
        }

        return $hydrated;
    }

    /**
     * Text stays a plain string; media become AG-UI input parts.
     *
     * @return string|list<array<string, mixed>>
     */
    protected function content(Message $message): string|array
    {
        $parts = array_values(array_filter(array_map($this->part(...), $message->getContentBlocks())));
        foreach ($parts as $part) {
            if ($part['type'] !== 'text') {
                return $parts;
            }
        }

        return $message->getContent() ?? '';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function part(ContentBlockInterface $block): ?array
    {
        return match (true) {
            $block instanceof ImageContent => $this->media('image', $block->content, $block->sourceType, $block->mediaType),
            $block instanceof AudioContent => $this->media('audio', $block->content, $block->sourceType, $block->mediaType),
            $block instanceof VideoContent => $this->media('video', $block->content, $block->sourceType, $block->mediaType),
            $block instanceof FileContent => $this->media('document', $block->content, $block->sourceType, $block->mediaType),
            $block instanceof TextContent && !$block instanceof ReasoningContent => ['type' => 'text', 'text' => $block->content],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function media(string $type, string $content, SourceType $sourceType, ?string $mediaType): ?array
    {
        return match ($sourceType) {
            SourceType::URL => ['type' => $type, 'source' => ['type' => 'url', 'value' => $content] + ($mediaType === null ? [] : ['mimeType' => $mediaType])],
            SourceType::BASE64 => ['type' => $type, 'source' => ['type' => 'data', 'value' => $content, 'mimeType' => $mediaType ?? 'application/octet-stream']],
            // A provider-hosted file ID has no AG-UI form.
            SourceType::ID => null,
        };
    }

    /**
     * A result accepted from a partial frontend delivery, settled as the waiting node settles it.
     *
     * @param array{result?: mixed, error?: string} $result
     * @throws JsonException
     */
    protected function acceptedResult(array $result): string|ToolOutput
    {
        if (isset($result['error'])) {
            return ToolOutput::error($result['error']);
        }

        return is_string($result['result']) ? $result['result'] : json_encode($result['result'], JSON_THROW_ON_ERROR);
    }

    /**
     * One confirmation per action, answered by the action ID.
     *
     * @return list<array<string, mixed>>
     */
    protected function confirmations(ApprovalRequest $request): array
    {
        return array_map(fn (Action $action): array => $this->withExpiry([
            'id' => $action->id,
            'reason' => 'confirmation',
            'message' => $action->reason ?? $request->getMessage(),
            'responseSchema' => [
                'type' => 'object',
                'properties' => ['approved' => ['type' => 'boolean'], 'reason' => ['type' => 'string']],
                'required' => ['approved'],
            ],
            'metadata' => $action->jsonSerialize(),
        ], $request), $request->getActions());
    }

    /**
     * @return array<string, mixed>
     * @throws WorkflowException
     */
    protected function interruption(InterruptRequest $request): array
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
     * @return iterable<ProtocolEvent>
     */
    protected function endReasoning(): iterable
    {
        if (! $this->reasoningStarted) {
            return;
        }

        yield new ProtocolEvent('REASONING_MESSAGE_END', [
            'messageId' => $this->reasoningMessageId,
        ]);

        yield new ProtocolEvent('REASONING_END', [
            'messageId' => $this->reasoningMessageId,
        ]);

        $this->reasoningStarted = false;
        $this->reasoningMessageId = null;
    }

    /**
     * @return iterable<ProtocolEvent>
     */
    protected function endText(): iterable
    {
        if (! $this->messageStarted || $this->currentMessageId === null) {
            return;
        }

        yield new ProtocolEvent('TEXT_MESSAGE_END', [
            'messageId' => $this->currentMessageId,
        ]);

        $this->messageStarted = false;
        $this->currentMessageId = null;
    }

    /**
     * Terminate a failed run instead of calling end().
     *
     * @return iterable<ProtocolEvent>
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

        $data = ['message' => $this->errorMessage($error)];

        if ($error->getCode() !== 0) {
            $data['code'] = (string) $error->getCode();
        }

        yield new ProtocolEvent('RUN_ERROR', $data);
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

        foreach ($this->endReasoning() as $event) {
            yield $event;
        }
        foreach ($this->endText() as $event) {
            yield $event;
        }

        if ($this->runId !== null) {
            yield new ProtocolEvent('RUN_FINISHED', [
                'threadId' => $this->threadId,
                'runId' => $this->runId,
            ]);
        }
    }
}
