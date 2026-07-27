<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
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

    protected ?string $currentTextId = null;

    /** @var array<string, string> */
    protected array $toolCallIds = [];

    public function transform(object $chunk): iterable
    {
        // Lazy init on the first chunk
        if (!$this->started) {
            $this->started = true;
            yield $this->sse(['type' => 'start', 'messageId' => $chunk->messageId]);
        }

        // A text part must be closed before any non-text part begins.
        if (!$chunk instanceof TextChunk && $this->currentTextId !== null) {
            yield $this->sse(['type' => 'text-end', 'id' => $this->currentTextId]);
            $this->currentTextId = null;
        }

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
        // Every delta of one text part must share ONE id, and the part must be framed by
        // text-start ... text-end — otherwise the UI message stream client (AI SDK v5+)
        // cannot assemble the deltas into a message part.
        // https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol#text-parts
        if ($this->currentTextId === null) {
            $this->currentTextId = UniqueIdGenerator::generateId();
            yield $this->sse(['type' => 'text-start', 'id' => $this->currentTextId]);
        }

        yield $this->sse([
            'type' => 'text-delta',
            'id' => $this->currentTextId,
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

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        $callId = $this->generateId('call');
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
            'output' => $chunk->tool->getResult(),
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
        if ($this->currentTextId !== null) {
            yield $this->sse(['type' => 'text-end', 'id' => $this->currentTextId]);
            $this->currentTextId = null;
        }

        yield $this->sse(['type' => 'finish']);
        yield "data: [DONE]\n\n";
    }
}
