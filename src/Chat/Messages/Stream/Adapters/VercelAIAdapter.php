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
    protected ?string $messageId = null;

    /** @var array<string, string> */
    protected array $toolCallIds = [];

    public function transform(object $chunk): iterable
    {
        // Lazy init message ID on the first chunk
        if ($this->messageId === null) {
            $this->messageId = $this->generateId('msg');
            yield $this->sse(['type' => 'start', 'messageId' => $this->messageId]);
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

    protected function handleToolCall(ToolCallChunk $chunk): iterable
    {
        foreach ($chunk->tools as $tool) {
            $callId = $this->generateId('call');
            $this->toolCallIds[$tool->getName()] = $callId;

            yield $this->sse([
                'type' => 'tool-input-available',
                'toolCallId' => $callId,
                'toolName' => $tool->getName(),
                'input' => $tool->getInputs(),
            ]);
        }
    }

    protected function handleToolResult(ToolResultChunk $chunk): iterable
    {
        foreach ($chunk->tools as $tool) {
            $callId = $this->toolCallIds[$tool->getName()] ?? $this->generateId('call');

            yield $this->sse([
                'type' => 'tool-output-available',
                'toolCallId' => $callId,
                'output' => $tool->getResult(),
            ]);
        }
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
