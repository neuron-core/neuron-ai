<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\UniqueIdGenerator;

/**
 * Adapter for Vercel AI SDK Data Stream Protocol.
 *
 * @see https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelAIAdapter extends SSEAdapter
{
    protected bool $started = false;

    /** @var array<string, string> */
    protected array $toolCallIds = [];

    /** @var array<string, bool> Track which tool calls emitted tool-input-start */
    protected array $toolInputStarted = [];

    public function transform(object $chunk): iterable
    {
        // Lazy init on the first chunk
        if (!$this->started) {
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
